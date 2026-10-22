<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model\ResourceModel\Tag;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Tag\Model\OperationIdGenerator;
use Magento\Tag\Model\TagRelation;
use Psr\Log\LoggerInterface;

/**
 * Tag relation resource model (port of Mage_Tag_Model_Resource_Tag_Relation)
 */
class Relation extends AbstractDb
{
    private const LOG_MODULE = 'Magento_Tag';

    /**
     * @var DateTime
     */
    private $dateTime;

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
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     * @param OperationIdGenerator $operationIdGenerator
     * @param string|null $connectionName
     */
    public function __construct(
        Context $context,
        DateTime $dateTime,
        LoggerInterface $logger,
        OperationIdGenerator $operationIdGenerator,
        $connectionName = null
    ) {
        $this->dateTime = $dateTime;
        $this->logger = $logger;
        $this->operationIdGenerator = $operationIdGenerator;
        parent::__construct($context, $connectionName);
    }

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init('tag_relation', 'tag_relation_id');
    }

    /**
     * Load by Tag and Customer (optionally product and store)
     *
     * @param TagRelation $model
     * @return $this
     */
    public function loadByTagCustomer($model)
    {
        if ($model->getTagId() && $model->getCustomerId()) {
            $connection = $this->getConnection();
            $mainTable = $this->getMainTable();
            $tagTable = $this->getTable('tag');
            $bind = [
                'tag_id' => $model->getTagId(),
                'customer_id' => $model->getCustomerId(),
            ];

            $select = $connection->select()
                ->from($mainTable)
                ->join($tagTable, $tagTable . '.tag_id = ' . $mainTable . '.tag_id')
                ->where($mainTable . '.tag_id = :tag_id')
                ->where('customer_id = :customer_id');

            if ($model->getProductId()) {
                $select->where($mainTable . '.product_id = :product_id');
                $bind['product_id'] = $model->getProductId();
            }

            if ($model->hasStoreId()) {
                $select->where($mainTable . '.store_id = :store_id');
                $bind['store_id'] = $model->getStoreId();
            }

            $data = $connection->fetchRow($select, $bind);
            $model->setData(is_array($data) ? $data : []);
        }

        return $this;
    }

    /**
     * Retrieve tagged product ids
     *
     * @param TagRelation $model
     * @return array
     */
    public function getProductIds($model)
    {
        $connection = $this->getConnection();
        $mainTable = $this->getMainTable();
        $bind = [
            'tag_id' => $model->getTagId(),
        ];
        $select = $connection->select()
            ->from($mainTable, 'product_id')
            ->where($mainTable . '.tag_id = :tag_id');

        if ($model->getCustomerId() !== null) {
            $select->where($mainTable . '.customer_id = :customer_id');
            $bind['customer_id'] = $model->getCustomerId();
        }

        if ($model->hasStoreId()) {
            $select->where($mainTable . '.store_id = :store_id');
            $bind['store_id'] = $model->getStoreId();
        }

        if ($model->getStatusFilter() !== null) {
            $tagTable = $this->getTable('tag');
            $select->join($tagTable, $tagTable . '.tag_id = ' . $mainTable . '.tag_id')
                ->where($tagTable . '.status = :t_status');
            $bind['t_status'] = $model->getStatusFilter();
        }

        return $connection->fetchCol($select, $bind);
    }

    /**
     * Whether the tag/product (in the model's store, when set) has at least one ACTIVE relation
     *
     * Read-only diagnostic helper (not present in M1).
     *
     * @param TagRelation $model
     * @return bool
     */
    public function hasActiveProductRelation($model): bool
    {
        $connection = $this->getConnection();
        $mainTable = $this->getMainTable();
        $bind = [
            'tag_id' => $model->getTagId(),
            'product_id' => $model->getProductId(),
            'active' => TagRelation::STATUS_ACTIVE,
        ];
        $select = $connection->select()
            ->from($mainTable, ['tag_relation_id'])
            ->where($mainTable . '.tag_id = :tag_id')
            ->where($mainTable . '.product_id = :product_id')
            ->where($mainTable . '.active = :active')
            ->limit(1);
        if ($model->hasStoreId()) {
            $select->where($mainTable . '.store_id = :store_id');
            $bind['store_id'] = $model->getStoreId();
        }
        return (bool)$connection->fetchOne($select, $bind);
    }

    /**
     * Retrieve tag ids related to the product(s) of the model
     *
     * @param TagRelation $model
     * @return array
     */
    public function getRelatedTagIds($model)
    {
        $productIds = is_array($model->getProductId()) ? $model->getProductId() : [$model->getProductId()];
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), 'tag_id')
            ->where('product_id IN(?)', $productIds)
            ->order('tag_id');
        return $connection->fetchCol($select);
    }

    /**
     * Deactivate tag relations by tag and customer
     *
     * @param int|string|null $tagId
     * @param int|string|null $customerId
     * @return $this
     */
    public function deactivate($tagId, $customerId)
    {
        $connection = $this->getConnection();
        $condition = [
            'tag_id = ?' => $tagId,
            'customer_id = ?' => $customerId,
        ];

        // Read-only before-state snapshot for logging
        $select = $connection->select()
            ->from($this->getMainTable(), ['tag_relation_id', 'active'])
            ->where('tag_id = ?', $tagId)
            ->where('customer_id = ?', $customerId);
        $beforeActive = $connection->fetchPairs($select);

        $data = ['active' => TagRelation::STATUS_NOT_ACTIVE];
        $affected = $connection->update($this->getMainTable(), $data, $condition);

        $context = [
            'module' => self::LOG_MODULE,
            'operation' => 'relation.deactivate',
            'operation_id' => $this->operationIdGenerator->getOperationId(),
            'tag_id' => $tagId,
            'customer_id' => $customerId,
        ];
        if (empty($beforeActive)) {
            $this->logger->warning(
                'No tag relation found to deactivate',
                $context + ['outcome' => 'skipped', 'error_code' => 'relation.deactivate.not_found']
            );
        } else {
            $this->logger->info(
                'Tag relations deactivated',
                $context + [
                    'affected_count' => (int)$affected,
                    'relation_ids' => array_keys($beforeActive),
                    'before_active' => array_map('intval', $beforeActive),
                    'after_active' => TagRelation::STATUS_NOT_ACTIVE,
                ]
            );
        }
        return $this;
    }

    /**
     * Add TAG to PRODUCT relations (replaces the tag's product set for the model's store)
     *
     * @param TagRelation $model
     * @return $this
     * @throws \InvalidArgumentException when the added product ids are not an array (M1 never coerced them)
     */
    public function addRelations($model)
    {
        $addedIds = $model->getAddedProductIds();
        if (!is_array($addedIds)) {
            throw new \InvalidArgumentException('Tag relation product ids must be an array.');
        }

        $bind = [
            'tag_id' => $model->getTagId(),
            'store_id' => $model->getStoreId(),
        ];
        $connection = $this->getConnection();

        $select = $connection->select()
            ->from($this->getMainTable(), 'product_id')
            ->where('tag_id = :tag_id')
            ->where('store_id = :store_id');
        $oldRelationIds = $connection->fetchCol($select, $bind);

        $insert = array_diff($addedIds, $oldRelationIds);
        $delete = array_diff($oldRelationIds, $addedIds);

        if (!empty($insert)) {
            $createdAt = $this->dateTime->gmtDate();
            $insertData = [];
            foreach ($insert as $value) {
                $insertData[] = [
                    'tag_id' => $model->getTagId(),
                    'store_id' => $model->getStoreId(),
                    'product_id' => $value,
                    'customer_id' => $model->getCustomerId(),
                    'created_at' => $createdAt,
                ];
            }

            $connection->insertMultiple($this->getMainTable(), $insertData);
        }

        if (!empty($delete)) {
            $connection->delete($this->getMainTable(), [
                'product_id IN (?)' => $delete,
                'store_id = ?' => $model->getStoreId(),
            ]);
        }

        $model->setData('inserted_product_ids', array_values($insert));
        $model->setData('deleted_product_ids', array_values($delete));

        return $this;
    }
}
