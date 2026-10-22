<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model\ResourceModel;

use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Tag\Model\AdminContext;
use Magento\Tag\Model\OperationIdGenerator;
use Magento\Tag\Model\Tag as TagModel;
use Psr\Log\LoggerInterface;

/**
 * Tag resource model (port of Mage_Tag_Model_Resource_Tag)
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Tag extends AbstractDb
{
    /**
     * Maximum stored tag name length (multibyte characters)
     */
    public const MAX_NAME_LENGTH = 255;

    private const LOG_MODULE = 'Magento_Tag';

    /**
     * @var StringUtils
     */
    private $string;

    /**
     * @var AdminContext
     */
    private $adminContext;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var OperationIdGenerator
     */
    private $operationIdGenerator;

    /**
     * Object ids (spl_object_id) of tags whose save was merged into an existing PENDING tag
     *
     * @var bool[]
     */
    private $mergedObjects = [];

    /**
     * @param Context $context
     * @param StringUtils $string
     * @param AdminContext $adminContext
     * @param LoggerInterface $logger
     * @param OperationIdGenerator $operationIdGenerator
     * @param string|null $connectionName
     */
    public function __construct(
        Context $context,
        StringUtils $string,
        AdminContext $adminContext,
        LoggerInterface $logger,
        OperationIdGenerator $operationIdGenerator,
        $connectionName = null
    ) {
        $this->string = $string;
        $this->adminContext = $adminContext;
        $this->logger = $logger;
        $this->operationIdGenerator = $operationIdGenerator;
        parent::__construct($context, $connectionName);
    }

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init('tag', 'tag_id');
    }

    /**
     * Initialize unique fields (application-level uniqueness on name; there is no DB unique index)
     *
     * @return $this
     */
    protected function _initUniqueFields()
    {
        $this->_uniqueFields = [[
            'field' => 'name',
            'title' => __('Tag'),
        ]];
        return $this;
    }

    /**
     * Save tag with start/completion/failure logging (tag.save)
     *
     * @param AbstractModel $object
     * @return $this
     * @throws \Exception
     * @throws AlreadyExistsException
     */
    public function save(AbstractModel $object)
    {
        $startTime = microtime(true);
        $context = [
            'module' => self::LOG_MODULE,
            'operation' => 'tag.save',
            'operation_id' => $this->operationIdGenerator->getOperationId(),
            'tag_id' => $object->getId(),
        ];
        $this->logger->info(
            'Tag save started',
            $context + [
                'is_new' => !$object->getId(),
                'before_status' => $object->getOrigData('status'),
                'requested_status' => $object->getData('status'),
            ]
        );
        try {
            parent::save($object);
        } catch (\Throwable $e) {
            unset($this->mergedObjects[spl_object_id($object)]);
            $this->logger->error(
                'Tag save failed',
                array_merge($context, [
                    'outcome' => 'failure',
                    'error_code' => $this->resolveSaveErrorCode($e),
                    'tag_id' => $object->getId(),
                    'tag_name' => $object->getData('name'),
                    'exception' => $e->getMessage(),
                    'duration_ms' => $this->getDurationMs($startTime),
                ])
            );
            throw $e;
        }
        unset($this->mergedObjects[spl_object_id($object)]);
        $this->logger->info(
            'Tag save completed',
            array_merge($context, [
                'outcome' => 'success',
                'tag_id' => $object->getId(),
                'after_status' => $object->getData('status'),
                'duration_ms' => $this->getDurationMs($startTime),
            ])
        );
        return $this;
    }

    /**
     * Loading tag by name (name truncated to 255 multibyte characters before the lookup)
     *
     * @param TagModel|DataObject $model
     * @param string|null $name
     * @return null|false
     */
    public function loadByName($model, $name)
    {
        if ($name) {
            $name = (string)$name;
            $originalLength = (int)$this->string->strlen($name);
            $truncated = false;
            if ($originalLength > self::MAX_NAME_LENGTH) {
                $name = $this->truncateName($name);
                $truncated = true;
            }

            $data = $this->fetchRowByName($name);
            $model->setData(is_array($data) ? $data : []);

            if ($truncated) {
                $this->logNameTruncated(
                    $originalLength,
                    'load_by_name',
                    is_array($data) ? ($data[$this->getIdFieldName()] ?? null) : null
                );
            }
        } else {
            return false;
        }

        return null;
    }

    /**
     * Before saving actions: merge-on-save and multibyte-safe name truncation
     *
     * @param AbstractModel|TagModel $object
     * @return $this
     */
    protected function _beforeSave(AbstractModel $object)
    {
        // Truncation (M1 performs it right after the merge lookup; the lookup itself truncates the
        // name the same way, so doing it first is equivalent and avoids a second log entry).
        $name = $object->getData('name');
        if ($name !== null) {
            $originalLength = (int)$this->string->strlen((string)$name);
            if ($originalLength > self::MAX_NAME_LENGTH) {
                $object->setData('name', $this->truncateName((string)$name));
                $this->logNameTruncated($originalLength, 'before_save', $object->getId());
            }
        }

        // Merge-on-save: a NEW tag saved as APPROVED whose name matches an existing PENDING tag
        // reuses the pending tag's id (the save becomes an update; _checkUnique excludes own id).
        if (!$object->getId() && $object->getData('status') == TagModel::STATUS_APPROVED) {
            $searchName = $object->getData('name');
            $existing = $searchName ? $this->fetchRowByName((string)$searchName) : false;
            if (is_array($existing)
                && !empty($existing[$this->getIdFieldName()])
                && $existing['status'] == TagModel::STATUS_PENDING
            ) {
                $object->setId($existing[$this->getIdFieldName()]);
                $this->mergedObjects[spl_object_id($object)] = true;
                $this->logger->info(
                    'New approved tag merged into existing pending tag',
                    [
                        'module' => self::LOG_MODULE,
                        'operation' => 'tag.merge',
                        'operation_id' => $this->operationIdGenerator->getOperationId(),
                        'merged_into_tag_id' => $existing[$this->getIdFieldName()],
                        'tag_id' => $existing[$this->getIdFieldName()],
                        'tag_name' => $object->getData('name'),
                        'previous_status' => TagModel::STATUS_PENDING,
                        'new_status' => TagModel::STATUS_APPROVED,
                        'admin_user_id' => $object->getData('admin_user_id'),
                    ]
                );
            }
        }

        return parent::_beforeSave($object);
    }

    /**
     * Saving tag's base popularity (admin context only) + read-only duplicate-name detection for new tags
     *
     * @param AbstractModel|TagModel $object
     * @return $this
     */
    protected function _afterSave(AbstractModel $object)
    {
        $isMerged = isset($this->mergedObjects[spl_object_id($object)]);
        if ($object->isObjectNew() && !$isMerged) {
            $this->detectDuplicateNames($object);
        }

        if (!$object->getData('store') || !$this->adminContext->isAdminContext()) {
            return parent::_afterSave($object);
        }

        $tagId = $object->isObjectNew() ? $object->getData('tag_id') : $object->getId();

        $this->getConnection()->insertOnDuplicate($this->getTable('tag_properties'), [
            'tag_id' => $tagId,
            'store_id' => $object->getData('store'),
            'base_popularity' => $object->getData('base_popularity') ? $object->getData('base_popularity') : 0,
        ]);

        return parent::_afterSave($object);
    }

    /**
     * Getting base popularity per store view for specified tag
     *
     * @param int $tagId
     * @return array
     * @deprecated Kept for parity with M1 (deprecated since 1.4.0.0)
     * @see \Magento\Tag\Model\ResourceModel\Tag::aggregate()
     */
    protected function _getExistingBasePopularity($tagId)
    {
        $connection = $this->getConnection();
        $selectSummary = $connection->select()
            ->from(
                ['main' => $this->getTable('tag_summary')],
                ['store_id', 'base_popularity']
            )
            ->where('main.tag_id = :tag_id')
            ->where('main.store_id != 0');

        return $connection->fetchAssoc($selectSummary, ['tag_id' => $tagId]);
    }

    /**
     * Get aggregation data per store view
     *
     * @param int $tagId
     * @return array
     * @deprecated Kept for parity with M1 (deprecated since 1.4.0.0)
     * @see \Magento\Tag\Model\ResourceModel\Tag::aggregate()
     */
    protected function _getAggregationPerStoreView($tagId)
    {
        $connection = $this->getConnection();
        $selectLocal = $connection->select()
            ->from(
                ['main' => $this->getTable('tag_relation')],
                [
                    'customers' => 'COUNT(DISTINCT main.customer_id)',
                    'products' => 'COUNT(DISTINCT main.product_id)',
                    'store_id',
                    'uses' => 'COUNT(main.tag_relation_id)',
                ]
            )
            ->join(
                ['store' => $this->getTable('store')],
                'store.store_id = main.store_id AND store.store_id > 0',
                []
            )
            ->join(
                ['product_website' => $this->getTable('catalog_product_website')],
                'product_website.website_id = store.website_id AND product_website.product_id = main.product_id',
                []
            )
            ->where('main.tag_id = :tag_id')
            ->where('main.active = 1')
            ->group('main.store_id');

        $selectLocalResult = $connection->fetchAll($selectLocal, ['tag_id' => $tagId]);

        $selectHistorical = $connection->select()
            ->from(
                ['main' => $this->getTable('tag_relation')],
                [
                    'historical_uses' => 'COUNT(main.tag_relation_id)',
                    'store_id',
                ]
            )
            ->join(
                ['store' => $this->getTable('store')],
                'store.store_id = main.store_id AND store.store_id > 0',
                []
            )
            ->join(
                ['product_website' => $this->getTable('catalog_product_website')],
                'product_website.website_id = store.website_id AND product_website.product_id = main.product_id',
                []
            )
            ->group('main.store_id')
            ->where('main.tag_id = :tag_id')
            ->where('main.active = 1');

        $selectHistoricalResult = $connection->fetchAll($selectHistorical, ['tag_id' => $tagId]);

        foreach ($selectHistoricalResult as $historical) {
            foreach ($selectLocalResult as $key => $local) {
                if ($local['store_id'] == $historical['store_id']) {
                    $selectLocalResult[$key]['historical_uses'] = $historical['historical_uses'];
                    break;
                }
            }
        }

        return $selectLocalResult;
    }

    /**
     * Get global aggregation data for row with store_id = 0
     *
     * @param int $tagId
     * @return array
     * @deprecated Kept for parity with M1 (deprecated since 1.4.0.0)
     * @see \Magento\Tag\Model\ResourceModel\Tag::aggregate()
     */
    protected function _getGlobalAggregation($tagId)
    {
        $connection = $this->getConnection();
        // customers and products stats
        $selectGlobal = $connection->select()
            ->from(
                ['main' => $this->getTable('tag_relation')],
                [
                    'customers' => 'COUNT(DISTINCT main.customer_id)',
                    'products' => 'COUNT(DISTINCT main.product_id)',
                    'store_id' => new Expression('0'),
                    'uses' => 'COUNT(main.tag_relation_id)',
                ]
            )
            ->join(
                ['store' => $this->getTable('store')],
                'store.store_id=main.store_id AND store.store_id>0',
                []
            )
            ->join(
                ['product_website' => $this->getTable('catalog_product_website')],
                'product_website.website_id = store.website_id AND product_website.product_id = main.product_id',
                []
            )
            ->where('main.tag_id = :tag_id')
            ->where('main.active = 1');
        $result = $connection->fetchRow($selectGlobal, ['tag_id' => $tagId]);
        if (!$result) {
            return [];
        }

        // historical uses stats
        $selectHistoricalGlobal = $connection->select()
            ->from(
                ['main' => $this->getTable('tag_relation')],
                ['historical_uses' => 'COUNT(main.tag_relation_id)']
            )
            ->join(
                ['store' => $this->getTable('store')],
                'store.store_id = main.store_id AND store.store_id > 0',
                []
            )
            ->join(
                ['product_website' => $this->getTable('catalog_product_website')],
                'product_website.website_id = store.website_id AND product_website.product_id = main.product_id',
                []
            )
            ->where('main.tag_id = :tag_id')
            ->where('main.active = 1');
        $result['historical_uses'] = (int)$connection->fetchOne($selectHistoricalGlobal, ['tag_id' => $tagId]);

        return $result;
    }

    /**
     * Getting statistics data into buffer. Replacing our buffer array with new statistics and incoming data.
     *
     * @param TagModel $object
     * @return TagModel
     * @deprecated Kept for parity with M1 (deprecated since 1.4.0.0); summary is maintained by the indexer
     * @see \Magento\Tag\Model\ResourceModel\Tag\Collection::addSummary()
     */
    public function aggregate($object)
    {
        $tagId = (int)$object->getId();
        $storeId = (int)$object->getData('store');

        // create final summary from existing data and add specified base popularity
        $finalSummary = $this->_getExistingBasePopularity($tagId);
        if ($object->hasData('base_popularity') && $storeId) {
            $finalSummary[$storeId]['store_id'] = $storeId;
            $finalSummary[$storeId]['base_popularity'] = $object->getData('base_popularity');
        }

        // calculate aggregation data
        $summaries = $this->_getAggregationPerStoreView($tagId);
        $summariesGlobal = $this->_getGlobalAggregation($tagId);
        if ($summariesGlobal) {
            $summaries[] = $summariesGlobal;
        }

        // override final summary with aggregated data
        foreach ($summaries as $row) {
            $storeId = (int)$row['store_id'];
            foreach ($row as $key => $value) {
                $finalSummary[$storeId][$key] = $value;
            }
        }

        // prepare static parameters to final summary for insertion
        foreach ($finalSummary as $key => $row) {
            $finalSummary[$key]['tag_id'] = $tagId;
            foreach (['base_popularity', 'popularity', 'historical_uses', 'uses', 'products', 'customers'] as $str) {
                if (!isset($row[$str])) {
                    $finalSummary[$key][$str] = 0;
                }
            }

            $finalSummary[$key]['popularity'] = $finalSummary[$key]['historical_uses'];
        }

        // remove old and insert new data
        $connection = $this->getConnection();
        $connection->delete(
            $this->getTable('tag_summary'),
            ['tag_id = ?' => $tagId]
        );
        if (!empty($finalSummary)) {
            $connection->insertMultiple($this->getTable('tag_summary'), array_values($finalSummary));
        }

        $this->logger->debug(
            'Tag summary aggregated',
            [
                'module' => self::LOG_MODULE,
                'operation' => 'tag.aggregate',
                'operation_id' => $this->operationIdGenerator->getOperationId(),
                'tag_id' => $tagId,
                'summary_rows' => count($finalSummary),
            ]
        );

        return $object;
    }

    /**
     * Decrementing tag products quantity as action for product delete
     *
     * @param array $tagsId
     * @return int The number of affected rows
     */
    public function decrementProducts(array $tagsId)
    {
        if ($tagsId === []) {
            return 0;
        }

        return $this->getConnection()->update(
            $this->getTable('tag_summary'),
            ['products' => new Expression('products - 1')],
            ['tag_id IN (?)' => $tagsId]
        );
    }

    /**
     * Add summary data to specified object
     *
     * @param TagModel $object
     * @return TagModel
     * @deprecated Kept for parity with M1 (deprecated since 1.4.0.0)
     * @see \Magento\Tag\Model\ResourceModel\Tag\Collection::addSummary()
     */
    public function addSummary($object)
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(['relation' => $this->getTable('tag_relation')], [])
            ->joinLeft(
                ['summary' => $this->getTable('tag_summary')],
                'relation.tag_id = summary.tag_id AND relation.store_id = summary.store_id',
                [
                    'customers',
                    'products',
                    'popularity',
                ]
            )
            ->where('relation.tag_id = :tag_id')
            ->where('relation.store_id = :store_id')
            ->limit(1);
        $bind = [
            'tag_id' => (int)$object->getId(),
            'store_id' => (int)$object->getData('store_id'),
        ];
        $row = $connection->fetchRow($select, $bind);
        if ($row) {
            $object->addData($row);
        }

        return $object;
    }

    /**
     * Retrieve select object for load object data (adds tag's base popularity when the flag is set)
     *
     * @param string $field
     * @param mixed $value
     * @param AbstractModel|TagModel $object
     * @return \Magento\Framework\DB\Select
     */
    protected function _getLoadSelect($field, $value, $object)
    {
        $select = parent::_getLoadSelect($field, $value, $object);
        if (method_exists($object, 'getAddBasePopularity')
            && $object->getAddBasePopularity()
            && $object->hasData('store_id')
        ) {
            $connection = $this->getConnection();
            $select->joinLeft(
                ['properties' => $this->getTable('tag_properties')],
                'properties.tag_id = ' . $this->getMainTable() . '.tag_id AND '
                . $connection->quoteInto('properties.store_id = ?', (int)$object->getData('store_id')),
                'base_popularity'
            );
        }

        return $select;
    }

    /**
     * Fetch store ids in which tag visible
     *
     * @param AbstractModel|TagModel $object
     * @return $this
     */
    protected function _afterLoad(AbstractModel $object)
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable('tag_summary'), ['store_id'])
            ->where('tag_id = :tag_id');
        $storeIds = $connection->fetchCol($select, ['tag_id' => $object->getId()]);

        $object->setData('visible_in_store_ids', $storeIds);

        return $this;
    }

    /**
     * Raw lookup of a tag row by (already truncated) name
     *
     * @param string $name
     * @return array|false
     */
    private function fetchRowByName(string $name)
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('name = :name');
        return $connection->fetchRow($select, ['name' => $name]);
    }

    /**
     * Multibyte-safe truncation to MAX_NAME_LENGTH characters
     *
     * @param string $name
     * @return string
     */
    private function truncateName(string $name): string
    {
        return (string)$this->string->substr($name, 0, self::MAX_NAME_LENGTH);
    }

    /**
     * Log tag.name.truncated warning
     *
     * @param int $originalLength
     * @param string $callSite
     * @param mixed $tagId
     * @return void
     */
    private function logNameTruncated(int $originalLength, string $callSite, $tagId): void
    {
        $this->logger->warning(
            'Tag name exceeded maximum length and was truncated',
            [
                'module' => self::LOG_MODULE,
                'operation' => 'tag.name.truncated',
                'operation_id' => $this->operationIdGenerator->getOperationId(),
                'error_code' => 'tag.name.truncated',
                'original_length' => $originalLength,
                'stored_length' => self::MAX_NAME_LENGTH,
                'call_site' => $callSite,
                'tag_id' => $tagId,
            ]
        );
    }

    /**
     * Read-only check for other rows sharing the name of a newly created tag (never alters the save)
     *
     * @param AbstractModel $object
     * @return void
     */
    private function detectDuplicateNames(AbstractModel $object): void
    {
        try {
            $name = $object->getData('name');
            if ($name === null || $name === '' || !$object->getId()) {
                return;
            }
            $connection = $this->getConnection();
            $select = $connection->select()
                ->from($this->getMainTable(), [$this->getIdFieldName()])
                ->where('name = :name')
                ->where($this->getIdFieldName() . ' != :tag_id');
            $duplicateIds = $connection->fetchCol($select, ['name' => (string)$name, 'tag_id' => $object->getId()]);
            if (!empty($duplicateIds)) {
                $this->logger->warning(
                    'Duplicate tag name detected after tag creation',
                    [
                        'module' => self::LOG_MODULE,
                        'operation' => 'tag.name.duplicate_detected',
                        'operation_id' => $this->operationIdGenerator->getOperationId(),
                        'error_code' => 'tag.name.duplicate_detected',
                        'tag_id' => $object->getId(),
                        'tag_name' => $name,
                        'duplicate_tag_ids' => array_values($duplicateIds),
                    ]
                );
            }
        } catch (\Throwable $e) {
            // Diagnostic check only: must never change save behavior.
            return;
        }
    }

    /**
     * Map a save exception to a tag.save error_code
     *
     * @param \Throwable $exception
     * @return string
     */
    private function resolveSaveErrorCode(\Throwable $exception): string
    {
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof AlreadyExistsException || $e instanceof DuplicateException) {
                return 'tag.save.unique_violation';
            }
            $message = (string)$e->getMessage();
            if (stripos($message, 'foreign key constraint') !== false || preg_match('/\b145[12]\b/', $message)) {
                return 'tag.save.fk_violation';
            }
            if (stripos($message, 'Duplicate entry') !== false || preg_match('/\b1062\b/', $message)) {
                return 'tag.save.unique_violation';
            }
        }
        return 'tag.save.failed';
    }

    /**
     * Elapsed milliseconds since $startTime
     *
     * @param float $startTime
     * @return float
     */
    private function getDurationMs(float $startTime): float
    {
        return round((microtime(true) - $startTime) * 1000, 2);
    }
}
