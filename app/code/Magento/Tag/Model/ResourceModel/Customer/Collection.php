<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model\ResourceModel\Customer;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\DB\Select;
use Magento\Tag\Model\OperationIdGenerator;

/**
 * Tags customer collection (port of Mage_Tag_Model_Resource_Customer_Collection)
 *
 * Each row is a customer joined with one of their tag relations ("tr") and the tag ("t").
 *
 * @api
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Collection extends \Magento\Customer\Model\ResourceModel\Customer\Collection
{
    /**
     * Allows disabling grouping
     *
     * @var bool
     */
    protected $_allowDisableGrouping = true;

    /**
     * Count attribute for count sql
     *
     * @var string
     */
    protected $_countAttribute = 'tr.tag_id';

    /**
     * Array with joined tables
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
     * @var ProductCollectionFactory
     */
    private ProductCollectionFactory $productCollectionFactory;

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
     * @param \Magento\Eav\Model\ResourceModel\Helper $resourceHelper
     * @param \Magento\Framework\Validator\UniversalFactory $universalFactory
     * @param \Magento\Framework\Model\ResourceModel\Db\VersionControl\Snapshot $entitySnapshot
     * @param \Magento\Framework\DataObject\Copy\Config $fieldsetConfig
     * @param ProductCollectionFactory $productCollectionFactory
     * @param OperationIdGenerator $operationIdGenerator
     * @param \Magento\Framework\DB\Adapter\AdapterInterface|null $connection
     * @param string $modelName
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
        \Magento\Eav\Model\ResourceModel\Helper $resourceHelper,
        \Magento\Framework\Validator\UniversalFactory $universalFactory,
        \Magento\Framework\Model\ResourceModel\Db\VersionControl\Snapshot $entitySnapshot,
        \Magento\Framework\DataObject\Copy\Config $fieldsetConfig,
        ProductCollectionFactory $productCollectionFactory,
        OperationIdGenerator $operationIdGenerator,
        ?\Magento\Framework\DB\Adapter\AdapterInterface $connection = null,
        $modelName = self::CUSTOMER_MODEL_NAME
    ) {
        $this->productCollectionFactory = $productCollectionFactory;
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
            $entitySnapshot,
            $fieldsetConfig,
            $connection,
            $modelName
        );
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        parent::_resetState();
        $this->_allowDisableGrouping = true;
        $this->_countAttribute = 'tr.tag_id';
        $this->_joinFlags = [];
        $this->_useAnalyticFunction = false;
    }

    /**
     * Prepare select
     *
     * @return $this
     */
    protected function _initSelect()
    {
        parent::_initSelect();
        $this->_joinFields();
        $this->_setIdFieldName('tag_relation_id');
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
     * Get flag's status about joined table.
     *
     * @param string $table
     * @return bool|null
     * @deprecated M1 1.3.2.3 getFlag() must be used instead
     * @see getFlag()
     */
    public function getJoinFlag($table)
    {
        return $this->getFlag($table);
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
     * Add filter by tag id
     *
     * @param int $tagId
     * @return $this
     */
    public function addTagFilter($tagId)
    {
        $this->getSelect()
            ->where('tr.tag_id = ?', $tagId);
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
        $this->getSelect()
            ->where('tr.product_id = ?', $productId);
        return $this;
    }

    /**
     * Apply filter by store id(s).
     *
     * @param int|int[] $storeId
     * @return $this
     */
    public function addStoreFilter($storeId)
    {
        $this->getSelect()->where('tr.store_id IN (?)', $storeId);
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
        $this->getSelect()
            ->where('t.status = ?', $status);
        return $this;
    }

    /**
     * Add desc order by tag relation id
     *
     * @return $this
     */
    public function addDescOrder()
    {
        $this->getSelect()
            ->order('tr.tag_relation_id desc');
        return $this;
    }

    /**
     * Add grouping by tag id
     *
     * @return $this
     */
    public function addGroupByTag()
    {
        $this->getSelect()
            ->group('tr.tag_id');

        /*
         * Allow analytic functions usage
         */
        $this->_useAnalyticFunction = true;

        $this->_allowDisableGrouping = true;
        return $this;
    }

    /**
     * Add grouping by customer id
     *
     * @return $this
     */
    public function addGroupByCustomer()
    {
        $this->getSelect()
            ->group('tr.customer_id');

        $this->_allowDisableGrouping = false;
        return $this;
    }

    /**
     * Disable grouping (customer/product rows are not grouped)
     *
     * @return $this
     */
    public function addGroupByCustomerProduct()
    {
        // Nothing need to group
        $this->_allowDisableGrouping = false;
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
        $this->getSelect()->where('tr.customer_id = ?', $customerId);
        return $this;
    }

    /**
     * Join tag_relation and tag tables to select
     *
     * Customer name parts are selected as individual EAV attributes exactly as in M1; addNameToSelect() is not
     * used because the joined "t.*" columns already expose the tag "name" column.
     *
     * @return void
     */
    protected function _joinFields()
    {
        $tagRelationTable = $this->getTable('tag_relation');
        $tagTable = $this->getTable('tag');

        // M1 parity: only firstname/middlename/lastname are selected; no full-name concatenation (as in M1)
        $this->addAttributeToSelect('firstname')
            ->addAttributeToSelect('middlename')
            ->addAttributeToSelect('lastname')
            ->addAttributeToSelect('email');

        $this->getSelect()
            ->join(
                ['tr' => $tagRelationTable],
                'tr.customer_id = e.entity_id',
                ['tag_relation_id', 'product_id', 'active', 'added_in' => 'store_id']
            )
            ->join(['t' => $tagTable], 't.tag_id = tr.tag_id', ['*']);
    }

    /**
     * Get SQL for get record count
     *
     * @return Select
     */
    public function getSelectCountSql()
    {
        $countSelect = parent::getSelectCountSql();

        if ($this->_allowDisableGrouping) {
            $countSelect->reset(Select::COLUMNS);
            $countSelect->reset(Select::GROUP);
            $countSelect->columns('COUNT(DISTINCT ' . $this->getCountAttribute() . ')');
        }

        return $countSelect;
    }

    /**
     * Add product names and SKUs ("product", "product_sku") to loaded items
     *
     * @return $this
     */
    public function addProductName()
    {
        $productsId = [];
        $productsSku = [];
        $productsData = [];

        foreach ($this->getItems() as $item) {
            $productsId[] = $item->getProductId();
        }

        $productsId = array_unique($productsId);

        /* small fix */
        if ($productsId === []) {
            return $this;
        }

        $collection = $this->productCollectionFactory->create()
            ->addAttributeToSelect('name')
            ->addAttributeToSelect('sku')
            ->addIdFilter($productsId);

        $collection->load();

        foreach ($collection->getItems() as $item) {
            $productsData[$item->getId()] = $item->getName();
            $productsSku[$item->getId()] = $item->getSku();
        }

        foreach ($this->getItems() as $item) {
            $item->setProduct($productsData[$item->getProductId()] ?? null);
            $item->setProductSku($productsSku[$item->getProductId()] ?? null);
        }

        return $this;
    }

    /**
     * Add product name ("product_name") and SKU ("product_sku") to select
     *
     * @return $this
     */
    public function addProductToSelect()
    {
        $productTableAlias = 'p';

        // add product fields
        $this->_select->joinLeft(
            [$productTableAlias => $this->getTable('catalog_product_entity')],
            'tr.product_id = ' . $productTableAlias . '.entity_id',
            ['product_sku' => 'sku']
        );

        // add product attributes to select
        foreach (['name' => 'value'] as $field => $fieldName) {
            $attr = $this->_eavConfig->getAttribute(Product::ENTITY, $field);
            $linkField = $attr->getEntity()->getLinkField();
            $this->_select->joinLeft(
                [$field => $attr->getBackend()->getTable()],
                $productTableAlias . '.' . $linkField . ' = ' . $field . '.' . $linkField
                . ' AND ' . $field . '.attribute_id = ' . (int)$attr->getId(),
                ['product_' . $field => $fieldName]
            );
        }

        return $this;
    }

    /**
     * Set attribute for count
     *
     * @param string $value
     * @return $this
     */
    public function setCountAttribute($value)
    {
        $this->_countAttribute = $value;
        return $this;
    }

    /**
     * Get attribute for count
     *
     * @return string
     */
    public function getCountAttribute()
    {
        return $this->_countAttribute;
    }

    /**
     * Add field to filter ("name" refers to the tag name)
     *
     * @param string|array $attribute
     * @param null|string|array $condition
     * @return $this
     */
    public function addFieldToFilter($attribute, $condition = null)
    {
        if ($attribute == 'name') {
            $where = $this->_getConditionSql('t.name', $condition);
            $this->getSelect()->where($where, null, Select::TYPE_CONDITION);
            return $this;
        }

        return parent::addFieldToFilter($attribute, $condition);
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
