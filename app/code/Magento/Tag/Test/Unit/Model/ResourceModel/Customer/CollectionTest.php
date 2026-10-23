<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model\ResourceModel\Customer;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\AbstractEntity;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;
use Magento\Eav\Model\EntityFactory as EavEntityFactory;
use Magento\Eav\Model\ResourceModel\Helper as EavResourceHelper;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactory;
use Magento\Framework\DataObject\Copy\Config as FieldsetConfig;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\VersionControl\Snapshot;
use Magento\Framework\Validator\UniversalFactory;
use Magento\Tag\Model\OperationIdGenerator;
use Magento\Tag\Model\ResourceModel\Customer\Collection;
use Magento\Tag\Test\Unit\Model\ResourceModel\SqlSelectTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for \Magento\Tag\Model\ResourceModel\Customer\Collection
 * (port of Mage_Tag_Model_Resource_Customer_Collection)
 *
 * The collection is built with its real (EAV) constructor: every dependency is a mock, the customer entity
 * resource is returned by the universal factory, and the adapter is a non-connecting MySQL adapter producing
 * real Select objects, so the resulting SQL parts are asserted.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(Collection::class)]
class CollectionTest extends TestCase
{
    use SqlSelectTrait;

    /**
     * @var Mysql|MockObject
     */
    private $connectionMock;

    /**
     * @var CustomerResource|MockObject
     */
    private $entityMock;

    /**
     * @var EavConfig|MockObject
     */
    private $eavConfigMock;

    /**
     * @var Collection
     */
    private Collection $collection;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->connectionMock = $this->createSqlConnectionMock();

        $this->entityMock = $this->createMock(CustomerResource::class);
        $this->entityMock->method('getConnection')->willReturn($this->connectionMock);
        $this->entityMock->method('getEntityTable')->willReturn('customer_entity');
        $this->entityMock->method('getTable')->willReturnArgument(0);
        $this->entityMock->method('getType')->willReturn('customer');
        $this->entityMock->method('getDefaultAttributes')->willReturn(['entity_id', 'email']);
        $this->entityMock->method('getAttribute')->willReturn(false);

        $universalFactoryMock = $this->createMock(UniversalFactory::class);
        $universalFactoryMock->method('create')->willReturn($this->entityMock);

        $this->eavConfigMock = $this->createMock(EavConfig::class);
        $this->eavConfigMock->method('getAttribute')->willReturnCallback(
            fn ($entityType, $code) => $this->createAttributeMock((string)$code, 10)
        );

        $this->collection = new Collection(
            $this->createMock(EntityFactory::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(FetchStrategyInterface::class),
            $this->createMock(ManagerInterface::class),
            $this->eavConfigMock,
            $this->createMock(ResourceConnection::class),
            $this->createMock(EavEntityFactory::class),
            $this->createMock(EavResourceHelper::class),
            $universalFactoryMock,
            $this->createMock(Snapshot::class),
            $this->createMock(FieldsetConfig::class),
            $this->createMock(ProductCollectionFactory::class),
            $this->createMock(OperationIdGenerator::class)
        );
    }

    /**
     * _initSelect() joins tag_relation "tr" and tag "t" to the customer entity
     *
     * @return void
     */
    public function testInitSelectJoinsRelationAndTag(): void
    {
        $from = $this->collection->getSelect()->getPart(Select::FROM);

        $this->assertSame(['e', 'tr', 't'], array_keys($from));
        $this->assertSame('customer_entity', $from['e']['tableName']);
        $this->assertSame(Select::INNER_JOIN, $from['tr']['joinType']);
        $this->assertSame('tag_relation', $from['tr']['tableName']);
        $this->assertSame('tr.customer_id = e.entity_id', $from['tr']['joinCondition']);
        $this->assertSame('t.tag_id = tr.tag_id', $from['t']['joinCondition']);
        $this->assertContains(['tr', 'store_id', 'added_in'], $this->collection->getSelect()->getPart(Select::COLUMNS));
        $this->assertSame('tag_relation_id', $this->collection->getIdFieldName());
    }

    /**
     * addGroupByTag() groups by tr.tag_id and keeps grouping disable-able for the count query
     *
     * @return void
     */
    public function testAddGroupByTag(): void
    {
        $this->collection->addGroupByCustomerProduct();

        $this->assertSame($this->collection, $this->collection->addGroupByTag());

        $this->assertSame(['tr.tag_id'], $this->collection->getSelect()->getPart(Select::GROUP));
        $this->assertTrue($this->readProperty('_allowDisableGrouping'));
        $this->assertTrue($this->readProperty('_useAnalyticFunction'));
    }

    /**
     * Count SQL after addGroupByTag(): GROUP is removed and COUNT(DISTINCT tr.tag_id) is selected
     *
     * @return void
     */
    public function testGetSelectCountSqlWithGroupByTagCountsDistinctTags(): void
    {
        $this->collection->addGroupByTag();
        $this->collection->addDescOrder();
        $this->collection->getSelect()->limit(20, 40);

        $countSelect = $this->collection->getSelectCountSql();

        $this->assertNotSame($this->collection->getSelect(), $countSelect);
        $this->assertSame([], $countSelect->getPart(Select::GROUP));
        $this->assertSame([], $countSelect->getPart(Select::ORDER));
        $this->assertNull($countSelect->getPart(Select::LIMIT_COUNT));
        $this->assertSame(
            ['COUNT(DISTINCT tr.tag_id)'],
            $this->columnExpressions($countSelect)
        );
        $this->assertStringStartsWith(
            'SELECT COUNT(DISTINCT tr.tag_id) FROM `customer_entity` AS `e`',
            (string)$countSelect->assemble()
        );

        // the collection select itself keeps its grouping
        $this->assertSame(['tr.tag_id'], $this->collection->getSelect()->getPart(Select::GROUP));
    }

    /**
     * Count SQL uses the configured count attribute (setCountAttribute())
     *
     * @return void
     */
    public function testGetSelectCountSqlUsesCountAttribute(): void
    {
        $this->assertSame('tr.tag_id', $this->collection->getCountAttribute());
        $this->assertSame($this->collection, $this->collection->setCountAttribute('tr.customer_id'));
        $this->assertSame('tr.customer_id', $this->collection->getCountAttribute());

        $this->assertSame(
            ['COUNT(DISTINCT tr.customer_id)'],
            $this->columnExpressions($this->collection->getSelectCountSql())
        );
    }

    /**
     * Without any grouping the default COUNT(DISTINCT <count attribute>) path is still taken
     *
     * @return void
     */
    public function testGetSelectCountSqlWithoutGroupingCountsDistinctCountAttribute(): void
    {
        $this->assertSame(
            ['COUNT(DISTINCT tr.tag_id)'],
            $this->columnExpressions($this->collection->getSelectCountSql())
        );
    }

    /**
     * addGroupByCustomerProduct() disables the grouping reset: the parent count query (COUNT(*)) is kept
     *
     * @return void
     */
    public function testAddGroupByCustomerProductKeepsParentCountSql(): void
    {
        $this->assertSame($this->collection, $this->collection->addGroupByCustomerProduct());
        $this->assertFalse($this->readProperty('_allowDisableGrouping'));
        $this->assertSame([], $this->collection->getSelect()->getPart(Select::GROUP));

        $this->assertSame(['COUNT(*)'], $this->columnExpressions($this->collection->getSelectCountSql()));
    }

    /**
     * addGroupByCustomer() groups by customer and the parent COUNT(DISTINCT <group>) count query is kept
     *
     * @return void
     */
    public function testAddGroupByCustomerKeepsParentDistinctGroupCount(): void
    {
        $this->collection->addGroupByCustomer();

        $this->assertFalse($this->readProperty('_allowDisableGrouping'));
        $countSelect = $this->collection->getSelectCountSql();
        $this->assertSame(['COUNT(DISTINCT tr.customer_id)'], $this->columnExpressions($countSelect));
        $this->assertSame([], $countSelect->getPart(Select::GROUP));
    }

    /**
     * addDescOrder() orders by tr.tag_relation_id desc
     *
     * @return void
     */
    public function testAddDescOrder(): void
    {
        $this->assertSame($this->collection, $this->collection->addDescOrder());

        $this->assertSame(
            [['tr.tag_relation_id', 'desc']],
            $this->collection->getSelect()->getPart(Select::ORDER)
        );
    }

    /**
     * addProductToSelect() left-joins the product entity (sku) and the product "name" attribute table
     *
     * @return void
     */
    public function testAddProductToSelect(): void
    {
        $productEntityMock = $this->createMock(AbstractEntity::class);
        $productEntityMock->method('getLinkField')->willReturn('row_id');
        $backendMock = $this->createMock(AbstractBackend::class);
        $backendMock->method('getTable')->willReturn('catalog_product_entity_varchar');
        $nameAttributeMock = $this->createMock(AbstractAttribute::class);
        $nameAttributeMock->method('getEntity')->willReturn($productEntityMock);
        $nameAttributeMock->method('getBackend')->willReturn($backendMock);
        $nameAttributeMock->method('getId')->willReturn('73');

        $eavConfigMock = $this->createMock(EavConfig::class);
        $eavConfigMock->expects($this->once())
            ->method('getAttribute')
            ->with(Product::ENTITY, 'name')
            ->willReturn($nameAttributeMock);
        $this->writeProperty('_eavConfig', $eavConfigMock);

        $this->assertSame($this->collection, $this->collection->addProductToSelect());

        $select = $this->collection->getSelect();
        $from = $select->getPart(Select::FROM);
        $this->assertSame(['e', 'tr', 't', 'p', 'name'], array_keys($from));

        $this->assertSame(Select::LEFT_JOIN, $from['p']['joinType']);
        $this->assertSame('catalog_product_entity', $from['p']['tableName']);
        $this->assertSame('tr.product_id = p.entity_id', $from['p']['joinCondition']);

        $this->assertSame(Select::LEFT_JOIN, $from['name']['joinType']);
        $this->assertSame('catalog_product_entity_varchar', $from['name']['tableName']);
        $this->assertSame(
            'p.row_id = name.row_id AND name.attribute_id = 73',
            $from['name']['joinCondition']
        );

        $columns = $select->getPart(Select::COLUMNS);
        $this->assertContains(['p', 'sku', 'product_sku'], $columns);
        $this->assertContains(['name', 'value', 'product_name'], $columns);
    }

    /**
     * _renderOrders(): orders that are not EAV attributes are added as raw expressions, only once
     *
     * @return void
     */
    public function testRenderOrdersAddsNonAttributeOrdersAsExpressions(): void
    {
        $this->collection->setOrder('tr.tag_relation_id', 'DESC');
        $this->collection->setOrder('t.name', 'ASC');

        $this->invokeRenderOrders();
        $this->invokeRenderOrders();

        $orders = $this->collection->getSelect()->getPart(Select::ORDER);
        $this->assertCount(2, $orders);
        $this->assertInstanceOf(\Zend_Db_Expr::class, $orders[0]);
        $this->assertSame('tr.tag_relation_id DESC', (string)$orders[0]);
        $this->assertSame('t.name ASC', (string)$orders[1]);
        $this->assertStringContainsString(
            'ORDER BY tr.tag_relation_id DESC, t.name ASC',
            (string)$this->collection->getSelect()->assemble()
        );
    }

    /**
     * _renderOrders(): an order already rendered by the EAV parent under the same field name is not duplicated
     *
     * @return void
     */
    public function testRenderOrdersDoesNotDuplicateAppliedOrder(): void
    {
        // A join field without table alias is rendered by the EAV parent as "<field> <dir>"
        $joinFields = $this->readProperty('_joinFields');
        $joinFields['tag_name'] = ['table' => null, 'field' => 'tag_name'];
        $this->writeProperty('_joinFields', $joinFields);
        $this->collection->setOrder('tag_name', 'ASC');
        $this->collection->setOrder('tr.tag_relation_id', 'DESC');

        $this->invokeRenderOrders();

        $orders = $this->collection->getSelect()->getPart(Select::ORDER);
        $this->assertCount(2, $orders);
        $this->assertSame(['tag_name', 'ASC'], $orders[0]);
        $this->assertSame('tr.tag_relation_id DESC', (string)$orders[1]);
    }

    /**
     * @param string $code
     * @param int $id
     * @return AbstractAttribute|MockObject
     */
    private function createAttributeMock(string $code, int $id): MockObject
    {
        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getId')->willReturn($id);
        return $attribute;
    }

    /**
     * Column expressions of a select as strings
     *
     * @param Select $select
     * @return string[]
     */
    private function columnExpressions(Select $select): array
    {
        return array_map(fn (array $column) => (string)$column[1], $select->getPart(Select::COLUMNS));
    }

    /**
     * @return void
     */
    private function invokeRenderOrders(): void
    {
        (new \ReflectionMethod(Collection::class, '_renderOrders'))->invoke($this->collection);
    }

    /**
     * @param string $name
     * @return mixed
     */
    private function readProperty(string $name)
    {
        return (new \ReflectionProperty(Collection::class, $name))->getValue($this->collection);
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return void
     */
    private function writeProperty(string $name, $value): void
    {
        (new \ReflectionProperty(Collection::class, $name))->setValue($this->collection, $value);
    }
}
