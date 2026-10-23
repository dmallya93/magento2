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
use Magento\Tag\Model\ResourceModel\CollectionLoadLoggerTrait;
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
    use CollectionLoadLoggerTrait;

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

        return $this->runLoggedLoad(function () use ($printQuery, $logQuery) {
            parent::load($printQuery, $logQuery);
        });
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

}
