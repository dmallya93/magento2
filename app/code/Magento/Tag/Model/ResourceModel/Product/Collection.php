<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model\ResourceModel\Product;

use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Catalog\Model\Indexer\Product\Price\PriceTableResolver;
use Magento\Catalog\Model\Product\Gallery\ReadHandler as GalleryReadHandler;
use Magento\Catalog\Model\ResourceModel\Category;
use Magento\Catalog\Model\ResourceModel\Product\Collection\ProductLimitationFactory;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\CatalogUrlRewrite\Model\Storage\DbStorage;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Indexer\DimensionFactory;
use Magento\Tag\Model\Logging\Context as LogContext;
use Magento\Tag\Model\OperationIdGenerator;
use Magento\Tag\Model\ResourceModel\Tag\CollectionFactory as TagCollectionFactory;
use Magento\Tag\Model\TagRelation;

/**
 * Tagged product(s) collection (port of Mage_Tag_Model_Resource_Product_Collection)
 *
 * @api
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 */
class Collection extends \Magento\Catalog\Model\ResourceModel\Product\Collection
{
    /**
     * Customer Id Filter
     *
     * @var int|int[]|null
     */
    protected $_customerFilterId;

    /**
     * Tag Id Filter
     *
     * @var int|null
     */
    protected $_tagIdFilter;

    /**
     * Join Flags
     *
     * @var array
     */
    protected $_joinFlags = [];

    /**
     * Kept for parity with M1 (analytic function hint for count queries); not used by the M2 MySQL adapter.
     *
     * @var bool
     */
    protected $_useAnalyticFunction = false;

    /**
     * @var TagCollectionFactory
     */
    private TagCollectionFactory $tagCollectionFactory;

    /**
     * @var OperationIdGenerator
     */
    private OperationIdGenerator $operationIdGenerator;

    /**
     * @param \Magento\Framework\Data\Collection\EntityFactory $entityFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Framework\Data\Collection\Db\FetchStrategyInterface $fetchStrategy
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Eav\Model\Config $eavConfig
     * @param \Magento\Framework\App\ResourceConnection $resource
     * @param \Magento\Eav\Model\EntityFactory $eavEntityFactory
     * @param \Magento\Catalog\Model\ResourceModel\Helper $resourceHelper
     * @param \Magento\Framework\Validator\UniversalFactory $universalFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\Module\Manager $moduleManager
     * @param \Magento\Catalog\Model\Indexer\Product\Flat\State $catalogProductFlatState
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Catalog\Model\Product\OptionFactory $productOptionFactory
     * @param \Magento\Catalog\Model\ResourceModel\Url $catalogUrl
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Framework\Stdlib\DateTime $dateTime
     * @param GroupManagementInterface $groupManagement
     * @param TagCollectionFactory $tagCollectionFactory
     * @param OperationIdGenerator $operationIdGenerator
     * @param \Magento\Framework\DB\Adapter\AdapterInterface|null $connection
     * @param ProductLimitationFactory|null $productLimitationFactory
     * @param MetadataPool|null $metadataPool
     * @param TableMaintainer|null $tableMaintainer
     * @param PriceTableResolver|null $priceTableResolver
     * @param DimensionFactory|null $dimensionFactory
     * @param Category|null $categoryResourceModel
     * @param DbStorage|null $urlFinder
     * @param GalleryReadHandler|null $productGalleryReadHandler
     * @param Gallery|null $mediaGalleryResource
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\Data\Collection\EntityFactory $entityFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Data\Collection\Db\FetchStrategyInterface $fetchStrategy,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Eav\Model\Config $eavConfig,
        \Magento\Framework\App\ResourceConnection $resource,
        \Magento\Eav\Model\EntityFactory $eavEntityFactory,
        \Magento\Catalog\Model\ResourceModel\Helper $resourceHelper,
        \Magento\Framework\Validator\UniversalFactory $universalFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Module\Manager $moduleManager,
        \Magento\Catalog\Model\Indexer\Product\Flat\State $catalogProductFlatState,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Catalog\Model\Product\OptionFactory $productOptionFactory,
        \Magento\Catalog\Model\ResourceModel\Url $catalogUrl,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\Stdlib\DateTime $dateTime,
        GroupManagementInterface $groupManagement,
        TagCollectionFactory $tagCollectionFactory,
        OperationIdGenerator $operationIdGenerator,
        ?\Magento\Framework\DB\Adapter\AdapterInterface $connection = null,
        ?ProductLimitationFactory $productLimitationFactory = null,
        ?MetadataPool $metadataPool = null,
        ?TableMaintainer $tableMaintainer = null,
        ?PriceTableResolver $priceTableResolver = null,
        ?DimensionFactory $dimensionFactory = null,
        ?Category $categoryResourceModel = null,
        ?DbStorage $urlFinder = null,
        ?GalleryReadHandler $productGalleryReadHandler = null,
        ?Gallery $mediaGalleryResource = null
    ) {
        $this->tagCollectionFactory = $tagCollectionFactory;
        $this->operationIdGenerator = $operationIdGenerator;
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $eavConfig,
            $resource,
            $eavEntityFactory,
            $resourceHelper,
            $universalFactory,
            $storeManager,
            $moduleManager,
            $catalogProductFlatState,
            $scopeConfig,
            $productOptionFactory,
            $catalogUrl,
            $localeDate,
            $customerSession,
            $dateTime,
            $groupManagement,
            $connection,
            $productLimitationFactory,
            $metadataPool,
            $tableMaintainer,
            $priceTableResolver,
            $dimensionFactory,
            $categoryResourceModel,
            $urlFinder,
            $productGalleryReadHandler,
            $mediaGalleryResource
        );
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        parent::_resetState();
        $this->_customerFilterId = null;
        $this->_tagIdFilter = null;
        $this->_joinFlags = [];
        $this->_useAnalyticFunction = false;
    }

    /**
     * Initialize collection select
     *
     * @return $this
     */
    protected function _initSelect()
    {
        parent::_initSelect();

        $this->_joinFields();
        $this->getSelect()->group('e.entity_id');

        /*
         * Allow analytic function usage
         */
        $this->_useAnalyticFunction = true;

        return $this;
    }

    /**
     * Load collection and emit the "collection.load" log entry
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
     * Add tag visibility on stores
     *
     * @return $this
     */
    public function addStoresVisibility()
    {
        $this->setFlag('add_stores_after', true);
        return $this;
    }

    /**
     * Add tag visibility on stores process
     *
     * @return $this
     */
    protected function _addStoresVisibility()
    {
        $tagIds = [];
        foreach ($this as $item) {
            $tagIds[] = $item->getTagId();
        }

        $tagsStores = [];
        if ($tagIds !== []) {
            $select = $this->getConnection()->select()
                ->from($this->getTable('tag_relation'), ['store_id', 'tag_id'])
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
            if (isset($tagsStores[$item->getTagId()])) {
                $item->setStores($tagsStores[$item->getTagId()]);
            } else {
                $item->setStores([]);
            }
        }

        return $this;
    }

    /**
     * Add group by tag relation
     *
     * @return $this
     */
    public function addGroupByTag()
    {
        $this->getSelect()->group('relation.tag_relation_id');
        return $this;
    }

    /**
     * Add store ID filter (filters by the store the relation was added in)
     *
     * @param int|int[]|null $store
     * @return $this
     */
    public function addStoreFilter($store = null)
    {
        if ($store !== null) {
            $this->getSelect()->where('relation.store_id IN (?)', $store);
        }

        return $this;
    }

    /**
     * Set customer filter
     *
     * If incoming parameter is array and has element with key 'null'
     * then condition with IS NULL or IS NOT NULL will be added.
     * Otherwise condition with IN() will be added
     *
     * @param int|int[]|array $customerId
     * @return $this
     */
    public function addCustomerFilter($customerId)
    {
        if (is_array($customerId) && isset($customerId['null'])) {
            $condition = ($customerId['null']) ? 'IS NULL' : 'IS NOT NULL';
            $this->getSelect()->where('relation.customer_id ' . $condition);
            return $this;
        }

        $this->getSelect()->where('relation.customer_id IN(?)', $customerId);
        $this->_customerFilterId = $customerId;
        return $this;
    }

    /**
     * Set tag filter
     *
     * @param int $tagId
     * @return $this
     */
    public function addTagFilter($tagId)
    {
        $this->getSelect()->where('relation.tag_id = ?', $tagId);
        $this->setFlag('distinct', true);
        return $this;
    }

    /**
     * Add tag status filter
     *
     * @param int $status
     * @return $this
     */
    public function addStatusFilter($status)
    {
        $this->getSelect()->where('t.status = ?', $status);
        return $this;
    }

    /**
     * Set DESC order to collection
     *
     * @param string $dir
     * @return $this
     */
    public function setDescOrder($dir = 'DESC')
    {
        $this->setOrder('relation.tag_relation_id', $dir);
        return $this;
    }

    /**
     * Add popularity of the given tag per product
     *
     * @param int $tagId
     * @param int|null $storeId
     * @return $this
     */
    public function addPopularity($tagId, $storeId = null)
    {
        $tagRelationTable = $this->getTable('tag_relation');

        $condition = [
            'prelation.product_id=e.entity_id',
        ];

        if ($storeId !== null) {
            $condition[] = $this->getConnection()->quoteInto('prelation.store_id = ?', $storeId);
        }

        $condition = implode(' AND ', $condition);
        $innerSelect = $this->getConnection()->select()
            ->from(
                ['relation' => $tagRelationTable],
                ['product_id', 'store_id', 'popularity' => 'COUNT(DISTINCT relation.tag_relation_id)']
            )
            ->where('relation.tag_id = ?', $tagId)
            ->group(['product_id', 'store_id']);

        $this->getSelect()
            ->joinLeft(
                ['prelation' => $innerSelect],
                $condition,
                ['popularity' => 'prelation.popularity']
            );

        $this->_tagIdFilter = $tagId;
        $this->setFlag('prelation', true);
        return $this;
    }

    /**
     * Add popularity filter (requires addPopularity() to have set the tag id)
     *
     * @param mixed $condition
     * @return $this
     */
    public function addPopularityFilter($condition)
    {
        $tagRelationTable = $this->getTable('tag_relation');

        $select = $this->getConnection()->select()
            ->from($tagRelationTable, ['product_id', 'popularity' => 'COUNT(DISTINCT tag_relation_id)'])
            ->where('tag_id = :tag_id')
            ->group('product_id')
            ->having($this->_getConditionSql('popularity', $condition));

        $prodIds = [];
        foreach ($this->getConnection()->fetchAll($select, ['tag_id' => $this->_tagIdFilter]) as $item) {
            $prodIds[] = $item['product_id'];
        }

        if ($prodIds !== []) {
            $this->getSelect()->where('e.entity_id IN(?)', $prodIds);
        } else {
            $this->getSelect()->where('e.entity_id IN(0)');
        }

        return $this;
    }

    /**
     * Set tag relation active filter to collection
     *
     * @return $this
     */
    public function setActiveFilter()
    {
        $active = TagRelation::STATUS_ACTIVE;
        $this->getSelect()->where('relation.active = ?', $active);
        if ($this->getFlag('prelation')) {
            $this->getSelect()->where('prelation.active = ?', $active);
        }

        return $this;
    }

    /**
     * Attach tag collections ("product_tags") to each loaded product
     *
     * @param int|null $storeId
     * @return $this
     */
    public function addProductTags($storeId = null)
    {
        foreach ($this->getItems() as $item) {
            $tagsCollection = $this->tagCollectionFactory->create();

            if ($storeId !== null) {
                $tagsCollection->addStoreFilter($storeId);
            }

            $tagsCollection->addPopularity()
                ->addProductFilter($item->getEntityId())
                ->addCustomerFilter($this->_customerFilterId)
                ->setActiveFilter();

            $tagsCollection->load();
            $item->setProductTags($tagsCollection);
        }

        return $this;
    }

    /**
     * Join tag and tag_relation tables
     *
     * @return $this
     */
    protected function _joinFields()
    {
        $tagTable = $this->getTable('tag');
        $tagRelationTable = $this->getTable('tag_relation');

        $this->addAttributeToSelect('name')
            ->addAttributeToSelect('price')
            ->addAttributeToSelect('small_image');

        $this->getSelect()
            ->join(['relation' => $tagRelationTable], 'relation.product_id = e.entity_id', [
                'product_id' => 'product_id',
                'item_store_id' => 'store_id',
            ])
            ->join(
                ['t' => $tagTable],
                't.tag_id = relation.tag_id',
                [
                    'tag_id',
                    'tag_status' => 'status',
                    'tag_name' => 'name',
                    'store_id' => $this->getConnection()->getCheckSql(
                        't.first_store_id = 0',
                        'relation.store_id',
                        't.first_store_id'
                    ),
                ]
            );

        return $this;
    }

    /**
     * Keep the per-row store_id selected by _joinFields() (M1 parity); do not overwrite with collection store
     *
     * @return $this
     */
    protected function prepareStoreId()
    {
        return $this;
    }

    /**
     * After load: add store visibility and dispatch tag_tag_product_collection_load_after
     *
     * @return $this
     */
    protected function _afterLoad()
    {
        parent::_afterLoad();

        if ($this->getFlag('add_stores_after')) {
            $this->_addStoresVisibility();
        }

        if (count($this) > 0) {
            $this->_eventManager->dispatch('tag_tag_product_collection_load_after', [
                'collection' => $this,
            ]);
        }

        return $this;
    }

    /**
     * Render SQL for retrieve product count
     *
     * @return Select
     */
    public function getSelectCountSql()
    {
        $countSelect = clone $this->getSelect();

        $countSelect->reset(Select::COLUMNS);
        $countSelect->reset(Select::ORDER);
        $countSelect->reset(Select::LIMIT_COUNT);
        $countSelect->reset(Select::LIMIT_OFFSET);
        $countSelect->reset(Select::GROUP);

        $field = $this->getFlag('group_tag') ? 'relation.tag_id' : 'e.entity_id';

        $expr = new \Zend_Db_Expr('COUNT('
            . ($this->getFlag('distinct') ? 'DISTINCT ' : '')
            . $field . ')');

        $countSelect->columns($expr);

        return $countSelect;
    }

    /**
     * Treat "order by" items as attributes to sort; non-attribute fields are added as raw expressions
     *
     * @return $this
     */
    protected function _renderOrders()
    {
        if (!$this->_isOrdersRendered) {
            parent::_renderOrders();

            $orders = $this->getSelect()->getPart(Select::ORDER);

            $appliedOrders = [];
            foreach ($orders as $order) {
                $appliedOrders[(string)(is_array($order) ? $order[0] : $order)] = true;
            }

            foreach ($this->_orders as $field => $direction) {
                if (empty($appliedOrders[$field])) {
                    $this->_select->order(new \Zend_Db_Expr($field . ' ' . $direction));
                }
            }
        }

        return $this;
    }

    /**
     * Set id field name as tag relation id
     *
     * @return $this
     */
    public function setRelationId()
    {
        $this->_setIdFieldName('tag_relation_id');
        return $this;
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
            'module' => LogContext::MODULE,
            'operation' => 'collection.load',
            'operation_id' => $this->operationIdGenerator->getOperationId(),
            'collection_class' => static::class,
            'filter_count' => count((array)$this->getSelect()->getPart(Select::WHERE))
                + count((array)$this->getSelect()->getPart(Select::HAVING)),
            'duration_ms' => LogContext::durationMs($startedAt),
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
