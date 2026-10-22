<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model\ResourceModel\Tag;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Tag\Model\ResourceModel\CollectionLoadLoggerTrait;
use Magento\Tag\Model\OperationIdGenerator;
use Magento\Tag\Model\TagRelation;
use Psr\Log\LoggerInterface;

/**
 * Tag collection (port of Mage_Tag_Model_Resource_Tag_Collection)
 *
 * @api
 */
class Collection extends AbstractCollection
{
    use CollectionLoadLoggerTrait;

    /**
     * Use getFlag('store_filter') & setFlag('store_filter', true) instead.
     *
     * @var bool
     */
    protected $_isStoreFilter = false;

    /**
     * Joined tables
     *
     * @var array
     */
    protected $_joinFlags = [];

    /**
     * Mapping for fields
     *
     * @var array
     */
    protected $_map = [
        'fields' => [
            'tag_id' => 'main_table.tag_id',
        ],
    ];

    /**
     * Kept for parity with M1 (analytic function hint for count queries); not used by the M2 MySQL adapter.
     *
     * @var bool
     */
    protected $_useAnalyticFunction = false;

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
        $this->_init(\Magento\Tag\Model\Tag::class, \Magento\Tag\Model\ResourceModel\Tag::class);
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        parent::_resetState();
        $this->_isStoreFilter = false;
        $this->_joinFlags = [];
        $this->_useAnalyticFunction = false;
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
            if ($this->getFlag('add_stores_after')) {
                $this->_addStoresVisibility();
            }
        });
    }

    /**
     * Set flag about joined table.
     *
     * @param string $table
     * @return $this
     * @deprecated M1 1.3.2.3 setFlag() must be used instead
     * @see setFlag()
     */
    public function setJoinFlag($table)
    {
        $this->setFlag($table, true);
        return $this;
    }

    /**
     * Unset value of join flag.
     *
     * @param string|null $table
     * @return $this
     * @deprecated M1 1.3.2.3 setFlag($table, false) must be used instead
     * @see setFlag()
     */
    public function unsetJoinFlag($table = null)
    {
        $this->setFlag($table, false);
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
     * Join relation and summary tables to retrieve popularity
     *
     * @param int|null $limit
     * @return $this
     */
    public function addPopularity($limit = null)
    {
        if (!$this->getFlag('popularity')) {
            $this->getSelect()
                ->joinLeft(
                    ['relation' => $this->getTable('tag_relation')],
                    'main_table.tag_id = relation.tag_id',
                    []
                )
                ->joinLeft(
                    ['summary' => $this->getTable('tag_summary')],
                    'relation.tag_id = summary.tag_id AND relation.store_id = summary.store_id',
                    ['popularity']
                )
                ->group('main_table.tag_id');

            /*
             * Allow analytic function usage
             */
            $this->_useAnalyticFunction = true;

            if ($limit !== null) {
                $this->getSelect()->limit($limit);
            }

            $this->setFlag('popularity');
        }

        return $this;
    }

    /**
     * Join summary data for given store(s)
     *
     * @param int|int[] $storeId
     * @return $this
     */
    public function addSummary($storeId)
    {
        if (!$this->getFlag('summary')) {
            $tableAlias = 'summary';
            $joinCondition = $this->getConnection()
                ->quoteInto(' AND ' . $tableAlias . '.store_id IN(?)', $storeId);

            $this->getSelect()
                ->joinLeft(
                    [$tableAlias => $this->getTable('tag_summary')],
                    'main_table.tag_id = ' . $tableAlias . '.tag_id' . $joinCondition,
                    ['store_id', 'popularity', 'customers', 'products']
                );

            $this->addFilterToMap('store_id', $tableAlias . '.store_id');
            $this->addFilterToMap('popularity', $tableAlias . '.popularity');
            $this->addFilterToMap('customers', $tableAlias . '.customers');
            $this->addFilterToMap('products', $tableAlias . '.products');

            $this->setFlag('summary', true);
        }

        return $this;
    }

    /**
     * Request store visibility to be added after load
     *
     * @return $this
     */
    public function addStoresVisibility()
    {
        $this->setFlag('add_stores_after', true);
        return $this;
    }

    /**
     * Add store visibility ("stores" data) to loaded items
     *
     * @return $this
     */
    protected function _addStoresVisibility()
    {
        $tagIds = $this->getColumnValues('tag_id');

        $tagsStores = [];
        if (count($tagIds)) {
            $select = $this->getConnection()->select()
                ->from($this->getTable('tag_summary'), ['store_id', 'tag_id'])
                ->where('tag_id IN(?)', $tagIds);
            $tagsRaw = $this->getConnection()->fetchAll($select);

            foreach ($tagsRaw as $tag) {
                if (!isset($tagsStores[$tag['tag_id']])) {
                    $tagsStores[$tag['tag_id']] = [];
                }

                $tagsStores[$tag['tag_id']][] = $tag['store_id'];
            }
        }

        foreach ($this as $item) {
            if (isset($tagsStores[$item->getId()])) {
                $item->setStores($tagsStores[$item->getId()]);
            } else {
                $item->setStores([]);
            }
        }

        return $this;
    }

    /**
     * Add field to filter (popularity / summary fields aware)
     *
     * @param string|array $field
     * @param null|string|array $condition
     * @return $this
     */
    public function addFieldToFilter($field, $condition = null)
    {
        if ($this->getFlag('relation') && $field == 'popularity') {
            // M1 parity: popularity filter is applied as HAVING on COUNT(relation.tag_relation_id), as in M1
            $this->getSelect()->having(
                $this->_getConditionSql('COUNT(relation.tag_relation_id)', $condition)
            );
        } elseif ($this->getFlag('summary')
            && is_string($field)
            && in_array($field, ['customers', 'products', 'uses', 'historical_uses', 'popularity'], true)
        ) {
            $this->getSelect()->where($this->_getConditionSql('summary.' . $field, $condition));
        } else {
            parent::addFieldToFilter($field, $condition);
        }

        return $this;
    }

    /**
     * Get SQL for get record count
     *
     * @return Select
     */
    public function getSelectCountSql()
    {
        $select = parent::getSelectCountSql();

        $select->reset(Select::COLUMNS);
        $select->reset(Select::GROUP);
        $select->reset(Select::HAVING);
        $select->columns('COUNT(DISTINCT main_table.tag_id)');
        return $select;
    }

    /**
     * Add filter by store
     *
     * @param int|int[] $storeId
     * @param bool $allFilter
     * @return $this
     */
    public function addStoreFilter($storeId, $allFilter = true)
    {
        if (!$this->getFlag('store_filter')) {
            $this->getSelect()->joinLeft(
                ['summary_store' => $this->getTable('tag_summary')],
                'main_table.tag_id = summary_store.tag_id'
            );

            $this->getSelect()->where('summary_store.store_id IN (?)', $storeId);

            $this->getSelect()->group('main_table.tag_id');

            if ($this->getFlag('relation') && $allFilter) {
                $this->getSelect()->where('relation.store_id IN (?)', $storeId);
            }

            if ($this->getFlag('prelation') && $allFilter) {
                $this->getSelect()->where('prelation.store_id IN (?)', $storeId);
            }

            /*
             * Allow Analytic functions usage
             */
            $this->_useAnalyticFunction = true;

            $this->setFlag('store_filter', true);
        }

        return $this;
    }

    /**
     * Add filtering by active relations
     *
     * @return $this
     */
    public function setActiveFilter()
    {
        $statusActive = TagRelation::STATUS_ACTIVE;
        $this->getSelect()->where('relation.active = ?', $statusActive);
        if ($this->getFlag('prelation')) {
            $this->getSelect()->where('prelation.active = ?', $statusActive);
        }

        return $this;
    }

    /**
     * Add filter by tag status
     *
     * @param int $status
     * @return $this
     */
    public function addStatusFilter($status)
    {
        $this->getSelect()->where('main_table.status = ?', $status);
        return $this;
    }

    /**
     * Add filter by product id
     *
     * @param int $productId
     * @return $this
     */
    public function addProductFilter($productId)
    {
        $this->addFieldToFilter('relation.product_id', $productId);
        if ($this->getFlag('prelation')) {
            $this->addFieldToFilter('prelation.product_id', $productId);
        }

        return $this;
    }

    /**
     * Add filter by customer id
     *
     * @param int $customerId
     * @return $this
     */
    public function addCustomerFilter($customerId)
    {
        $this->getSelect()
            ->where('relation.customer_id = ?', $customerId);
        if ($this->getFlag('prelation')) {
            $this->getSelect()
                ->where('prelation.customer_id = ?', $customerId);
        }

        return $this;
    }

    /**
     * Add grouping by tag id
     *
     * @return $this
     */
    public function addTagGroup()
    {
        $this->getSelect()->group('main_table.tag_id');
        $this->_useAnalyticFunction = true;
        return $this;
    }

    /**
     * Join tag_relation table
     *
     * @return $this
     */
    public function joinRel()
    {
        $this->setFlag('relation', true);
        $this->getSelect()->joinLeft(
            ['relation' => $this->getTable('tag_relation')],
            'main_table.tag_id=relation.tag_id'
        );
        return $this;
    }

}
