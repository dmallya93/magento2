<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Psr\Log\LoggerInterface;

/**
 * Tag relation model (port of Mage_Tag_Model_Tag_Relation)
 *
 * @method int getActive()
 * @method array getAddedProductIds()
 * @method int getCustomerId()
 * @method int|array getProductId()
 * @method array|int|string|null getStatusFilter()
 * @method int getStoreId()
 * @method int getTagId()
 * @method bool hasStoreId()
 * @method $this setActive(int $value)
 * @method $this setAddedProductIds(array $value)
 * @method $this setCreatedAt(string $value)
 * @method $this setCustomerId($value)
 * @method $this setProductId($value)
 * @method $this setProductIds(array $value)
 * @method $this setRelatedTagIds(array $value)
 * @method $this setStatusFilter($value)
 * @method $this setStoreId($value)
 * @method $this setTagId($value)
 */
class TagRelation extends AbstractModel implements IdentityInterface
{
    /**
     * Relation statuses
     */
    public const STATUS_ACTIVE = 1;
    public const STATUS_NOT_ACTIVE = 0;

    /**
     * Entity code. Can be used as part of method name for entity processing
     */
    public const ENTITY = 'tag_relation';

    /**
     * Cache tag (IdentityInterface)
     */
    public const CACHE_TAG = 'tag_relation';

    private const LOG_MODULE = 'Magento_Tag';

    /**
     * Event prefix for observer
     *
     * @var string
     */
    protected $_eventPrefix = 'tag_relation';

    /**
     * Event object name
     *
     * @var string
     */
    protected $_eventObject = 'tag_relation';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var OperationIdGenerator
     */
    private $operationIdGenerator;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param LoggerInterface $logger
     * @param OperationIdGenerator $operationIdGenerator
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        LoggerInterface $logger,
        OperationIdGenerator $operationIdGenerator,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->logger = $logger;
        $this->operationIdGenerator = $operationIdGenerator;
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(ResourceModel\Tag\Relation::class);
    }

    /**
     * Return cache identities of the relation (relation, its tag and its product)
     *
     * @return string[]
     */
    public function getIdentities()
    {
        $identities = [self::CACHE_TAG . '_' . $this->getId()];
        if ($this->getTagId()) {
            $identities[] = Tag::CACHE_TAG . '_' . $this->getTagId();
        }
        $productId = $this->getData('product_id');
        if ($productId && !is_array($productId)) {
            $identities[] = Product::CACHE_TAG . '_' . $productId;
        }
        return $identities;
    }

    /**
     * Load relation by Product (optional), tag, customer and store
     *
     * @param int|string|null $productId
     * @param int|string|null $tagId
     * @param int|string|null $customerId
     * @param int|string|null $storeId
     * @return $this
     */
    public function loadByTagCustomer($productId, $tagId, $customerId, $storeId = null)
    {
        $this->setProductId($productId);
        $this->setTagId($tagId);
        $this->setCustomerId($customerId);
        if ($storeId !== null) {
            $this->setStoreId($storeId);
        }

        $this->_getResource()->loadByTagCustomer($this);
        return $this;
    }

    /**
     * Retrieve Relation Product Ids
     *
     * @return array
     */
    public function getProductIds()
    {
        $ids = $this->getData('product_ids');
        if ($ids === null) {
            $ids = $this->_getResource()->getProductIds($this);
            $this->setProductIds($ids);
        }

        return $ids;
    }

    /**
     * Retrieve list of related tag ids for products specified in current object
     *
     * @return array
     */
    public function getRelatedTagIds()
    {
        if ($this->getData('related_tag_ids') === null) {
            $this->setRelatedTagIds($this->_getResource()->getRelatedTagIds($this));
        }

        return $this->getData('related_tag_ids');
    }

    /**
     * Whether the current tag/product/store has at least one ACTIVE relation (any customer)
     *
     * Read-only helper used for diagnostics (relation.decide `inactive_links_counted`).
     *
     * @return bool
     */
    public function hasActiveProductRelation(): bool
    {
        return $this->_getResource()->hasActiveProductRelation($this);
    }

    /**
     * Deactivate tag relations (using current settings)
     *
     * @return $this
     * @throws \Exception
     */
    public function deactivate()
    {
        $startTime = microtime(true);
        $context = [
            'module' => self::LOG_MODULE,
            'operation' => 'relation.deactivate',
            'operation_id' => $this->operationIdGenerator->getOperationId(),
            'tag_id' => $this->getTagId(),
            'customer_id' => $this->getCustomerId(),
        ];
        $beforeActive = $this->getData('active');
        $this->logger->info(
            'Tag relation deactivation started',
            $context + ['before_active' => $beforeActive === null ? null : (int)$beforeActive]
        );
        try {
            $this->_getResource()->deactivate($this->getTagId(), $this->getCustomerId());
        } catch (\Throwable $e) {
            $this->logger->error(
                'Tag relation deactivation failed',
                $context + [
                    'outcome' => 'failure',
                    'error_code' => 'relation.deactivate.failed',
                    'exception' => $e->getMessage(),
                    'duration_ms' => $this->getDurationMs($startTime),
                ]
            );
            throw $e;
        }
        $this->logger->info(
            'Tag relation deactivation completed',
            $context + [
                'outcome' => 'success',
                'before_active' => $beforeActive === null ? null : (int)$beforeActive,
                'after_active' => self::STATUS_NOT_ACTIVE,
                'duration_ms' => $this->getDurationMs($startTime),
            ]
        );
        return $this;
    }

    /**
     * Add TAG to PRODUCT relations
     *
     * @param Tag $model
     * @param array $productIds
     * @return $this
     * @throws \Exception
     */
    public function addRelations(Tag $model, $productIds = [])
    {
        $startTime = microtime(true);
        $productIds = is_array($productIds) ? $productIds : (array)$productIds;
        $this->setAddedProductIds($productIds);
        $this->setTagId($model->getTagId());
        $this->setCustomerId(null);
        $this->setStoreId($model->getStore());

        $context = [
            'module' => self::LOG_MODULE,
            'operation' => 'relation.batch_save',
            'operation_id' => $this->operationIdGenerator->getOperationId(),
            'tag_id' => $this->getTagId(),
            'store_id' => $this->getStoreId(),
            'requested_product_count' => count($productIds),
        ];
        $this->logger->info('Tag relation batch save started', $context);
        try {
            $this->_getResource()->addRelations($this);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Tag relation batch save failed',
                $context + [
                    'outcome' => 'failure',
                    'error_code' => $this->resolveErrorCode($e),
                    'exception' => $e->getMessage(),
                    'duration_ms' => $this->getDurationMs($startTime),
                ]
            );
            throw $e;
        }
        $this->logger->info(
            'Tag relation batch save completed',
            $context + [
                'outcome' => 'success',
                'inserted_product_ids' => array_values((array)$this->getData('inserted_product_ids')),
                'deleted_product_ids' => array_values((array)$this->getData('deleted_product_ids')),
                'duration_ms' => $this->getDurationMs($startTime),
            ]
        );
        return $this;
    }

    /**
     * Map a persistence exception to a relation.save error_code
     *
     * @param \Throwable $exception
     * @return string
     */
    private function resolveErrorCode(\Throwable $exception): string
    {
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof \Magento\Framework\DB\Adapter\DuplicateException
                || $e instanceof \Magento\Framework\Exception\AlreadyExistsException
            ) {
                return 'relation.save.duplicate';
            }
            $message = (string)$e->getMessage();
            if (stripos($message, 'foreign key constraint') !== false || preg_match('/\b145[12]\b/', $message)) {
                return 'relation.save.fk_violation';
            }
            if (stripos($message, 'Duplicate entry') !== false || preg_match('/\b1062\b/', $message)) {
                return 'relation.save.duplicate';
            }
        }
        return 'relation.save.failed';
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
