<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model\ResourceModel\Popular;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Tag\Model\OperationIdGenerator;
use Magento\Tag\Model\Tag;
use Psr\Log\LoggerInterface;

/**
 * Popular tags collection (port of Mage_Tag_Model_Resource_Popular_Collection)
 *
 * @api
 */
class Collection extends AbstractCollection
{
    /**
     * @var OperationIdGenerator
     */
    private OperationIdGenerator $operationIdGenerator;

    /**
     * @param EntityFactoryInterface $entityFactory
     * @param LoggerInterface $logger
     * @param FetchStrategyInterface $fetchStrategy
     * @param ManagerInterface $eventManager
     * @param OperationIdGenerator $operationIdGenerator
     * @param AdapterInterface|null $connection
     * @param AbstractDb|null $resource
     */
    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        OperationIdGenerator $operationIdGenerator,
        ?AdapterInterface $connection = null,
        ?AbstractDb $resource = null
    ) {
        $this->operationIdGenerator = $operationIdGenerator;
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $connection, $resource);
    }

    /**
     * Define resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(Tag::class, \Magento\Tag\Model\ResourceModel\Tag::class);
    }

    /**
     * Build the popular tags select: summary popularity of approved tags having products in the given store
     *
     * @param int $storeId
     * @return $this
     * @throws \Zend_Db_Select_Exception
     */
    public function joinFields($storeId = 0)
    {
        $this->getSelect()
            ->reset()
            ->from(
                ['tag_summary' => $this->getTable('tag_summary')],
                ['popularity' => 'tag_summary.popularity']
            )
            ->joinInner(
                ['tag' => $this->getTable('tag')],
                'tag.tag_id = tag_summary.tag_id AND tag.status = ' . Tag::STATUS_APPROVED
            )
            ->where('tag_summary.store_id = ?', $storeId)
            ->where('tag_summary.products > ?', 0)
            ->order('popularity ' . Select::SQL_DESC);

        return $this;
    }

    /**
     * Add filter by specified tag status
     *
     * @param int $statusCode
     * @return $this
     */
    public function addStatusFilter($statusCode)
    {
        $this->getSelect()->where('main_table.status = ?', $statusCode);
        return $this;
    }

    /**
     * Loads collection
     *
     * @param bool $printQuery
     * @param bool $logQuery
     * @return $this
     * @throws \Throwable
     */
    public function load($printQuery = false, $logQuery = false)
    {
        if ($this->isLoaded()) {
            return $this;
        }

        $startedAt = microtime(true);
        try {
            parent::load($printQuery, $logQuery);
        } catch (\Throwable $e) {
            $this->logCollectionLoad($startedAt, $e);
            throw $e;
        }
        $this->logCollectionLoad($startedAt);

        return $this;
    }

    /**
     * Set select limit
     *
     * @param int $limit
     * @return $this
     */
    public function limit($limit)
    {
        $this->getSelect()->limit($limit);
        return $this;
    }

    /**
     * Get SQL for get record count
     *
     * @return Select
     * @throws \Zend_Db_Select_Exception
     */
    public function getSelectCountSql()
    {
        $this->_renderFilters();
        $select = clone $this->getSelect();
        $select->reset(Select::ORDER);
        $select->reset(Select::LIMIT_COUNT);
        $select->reset(Select::LIMIT_OFFSET);

        $countSelect = $this->getConnection()->select();
        $countSelect->from(['a' => $select], 'COUNT(popularity)');
        return $countSelect;
    }

    /**
     * Emit the DEBUG "collection.load" entry (or ERROR on failure)
     *
     * @param float $startedAt
     * @param \Throwable|null $exception
     * @return void
     */
    private function logCollectionLoad(float $startedAt, ?\Throwable $exception = null): void
    {
        $context = [
            'module' => 'Magento_Tag',
            'operation' => 'collection.load',
            'operation_id' => $this->operationIdGenerator->getOperationId(),
            'collection_class' => static::class,
            'filter_count' => count((array)$this->getSelect()->getPart(Select::WHERE))
                + count((array)$this->getSelect()->getPart(Select::HAVING)),
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ];

        if ($exception !== null) {
            $context['outcome'] = 'failure';
            $context['error_code'] = 'collection.load.query_error';
            $context['exception'] = $exception->getMessage();
            $this->_logger->error('Tag collection load failed', $context);
            return;
        }

        $context['outcome'] = 'success';
        $context['result_count'] = count($this->_items);
        $this->_logger->debug('Tag collection loaded', $context);
    }
}
