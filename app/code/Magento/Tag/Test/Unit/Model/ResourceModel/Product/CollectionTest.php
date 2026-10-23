<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model\ResourceModel\Product;

use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Catalog\Model\Indexer\Product\Flat\State as FlatState;
use Magento\Catalog\Model\Indexer\Product\Price\PriceTableResolver;
use Magento\Catalog\Model\Product\Gallery\ReadHandler as GalleryReadHandler;
use Magento\Catalog\Model\Product\OptionFactory;
use Magento\Catalog\Model\ResourceModel\Category;
use Magento\Catalog\Model\ResourceModel\Helper as CatalogResourceHelper;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\ResourceModel\Product\Collection\ProductLimitation;
use Magento\Catalog\Model\ResourceModel\Product\Collection\ProductLimitationFactory;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Catalog\Model\ResourceModel\Url as CatalogUrl;
use Magento\CatalogUrlRewrite\Model\Storage\DbStorage;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\EntityFactory as EavEntityFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactory;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\DimensionFactory;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\Validator\UniversalFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tag\Model\OperationIdGenerator;
use Magento\Tag\Model\ResourceModel\Product\Collection;
use Magento\Tag\Model\ResourceModel\Tag\Collection as TagCollection;
use Magento\Tag\Model\ResourceModel\Tag\CollectionFactory as TagCollectionFactory;
use Magento\Tag\Test\Unit\Model\ResourceModel\SqlSelectTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for \Magento\Tag\Model\ResourceModel\Product\Collection
 * (port of Mage_Tag_Model_Resource_Product_Collection)
 *
 * The collection is built with its real (catalog product EAV) constructor: every dependency is a mock, the
 * product entity resource is returned by the universal factory (flat catalog disabled), and the adapter is a
 * non-connecting MySQL adapter producing real Select objects, so the resulting SQL parts are asserted.
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
     * @var ProductResource|MockObject
     */
    private $entityMock;

    /**
     * @var TagCollectionFactory|MockObject
     */
    private $tagCollectionFactoryMock;

    /**
     * @var Collection
     */
    private Collection $collection;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->connectionMock = $this->createSqlConnectionMock(['fetchAll']);

        $this->entityMock = $this->createMock(ProductResource::class);
        $this->entityMock->method('getConnection')->willReturn($this->connectionMock);
        $this->entityMock->method('getEntityTable')->willReturn('catalog_product_entity');
        $this->entityMock->method('getTable')->willReturnArgument(0);
        $this->entityMock->method('getType')->willReturn('catalog_product');
        $this->entityMock->method('getDefaultAttributes')->willReturn(['entity_id', 'sku']);

        $universalFactoryMock = $this->createMock(UniversalFactory::class);
        $universalFactoryMock->method('create')->willReturn($this->entityMock);

        $eavConfigMock = $this->createMock(EavConfig::class);
        $eavConfigMock->method('getAttribute')->willReturnCallback(
            function ($entityType, $code) {
                $attribute = $this->createMock(AbstractAttribute::class);
                $attribute->method('getAttributeCode')->willReturn((string)$code);
                $attribute->method('getId')->willReturn(crc32((string)$code) % 1000);
                return $attribute;
            }
        );

        $storeMock = $this->createMock(StoreInterface::class);
        $storeMock->method('getId')->willReturn(1);
        $storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $storeManagerMock->method('getStore')->willReturn($storeMock);

        $flatStateMock = $this->createMock(FlatState::class);
        $flatStateMock->method('isAvailable')->willReturn(false);

        $productLimitationFactoryMock = $this->createMock(ProductLimitationFactory::class);
        $productLimitationFactoryMock->method('create')->willReturn($this->createMock(ProductLimitation::class));

        $this->tagCollectionFactoryMock = $this->createMock(TagCollectionFactory::class);

        $this->collection = new Collection(
            $this->createMock(EntityFactory::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(FetchStrategyInterface::class),
            $this->createMock(ManagerInterface::class),
            $eavConfigMock,
            $this->createMock(ResourceConnection::class),
            $this->createMock(EavEntityFactory::class),
            $this->createMock(CatalogResourceHelper::class),
            $universalFactoryMock,
            $storeManagerMock,
            $this->createMock(ModuleManager::class),
            $flatStateMock,
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(OptionFactory::class),
            $this->createMock(CatalogUrl::class),
            $this->createMock(TimezoneInterface::class),
            $this->createMock(CustomerSession::class),
            $this->createMock(DateTime::class),
            $this->createMock(GroupManagementInterface::class),
            $this->tagCollectionFactoryMock,
            $this->createMock(OperationIdGenerator::class),
            null,
            $productLimitationFactoryMock,
            $this->createMock(MetadataPool::class),
            $this->createMock(TableMaintainer::class),
            $this->createMock(PriceTableResolver::class),
            $this->createMock(DimensionFactory::class),
            $this->createMock(Category::class),
            $this->createMock(DbStorage::class),
            $this->createMock(GalleryReadHandler::class),
            $this->createMock(Gallery::class)
        );
    }

    /**
     * _initSelect() joins tag_relation "relation" and tag "t" to the products and groups by product
     *
     * @return void
     */
    public function testInitSelectJoinsRelationAndTag(): void
    {
        $select = $this->collection->getSelect();
        $from = $select->getPart(Select::FROM);

        $this->assertSame(['e', 'relation', 't'], array_keys($from));
        $this->assertSame('catalog_product_entity', $from['e']['tableName']);
        $this->assertSame('relation.product_id = e.entity_id', $from['relation']['joinCondition']);
        $this->assertSame('t.tag_id = relation.tag_id', $from['t']['joinCondition']);
        $this->assertSame(['e.entity_id'], $select->getPart(Select::GROUP));

        $storeIdColumn = array_values(array_filter(
            $select->getPart(Select::COLUMNS),
            fn (array $column) => $column[2] === 'store_id'
        ));
        $this->assertCount(1, $storeIdColumn);
        $this->assertSame(
            'IF(t.first_store_id = 0, relation.store_id, t.first_store_id)',
            (string)$storeIdColumn[0][1]
        );
    }

    /**
     * addPopularityFilter() looks up matching product ids for the current tag and filters e.entity_id by them
     *
     * @return void
     */
    public function testAddPopularityFilterFiltersByMatchingProductIds(): void
    {
        $this->collection->addPopularity(7);

        $this->connectionMock->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (Select $select): bool {
                    $from = $select->getPart(Select::FROM);
                    $this->assertSame('tag_relation', reset($from)['tableName']);
                    $this->assertSame(['(tag_id = :tag_id)'], $select->getPart(Select::WHERE));
                    $this->assertSame(['product_id'], $select->getPart(Select::GROUP));
                    $this->assertSame(['(popularity >= 3)'], $select->getPart(Select::HAVING));
                    $this->assertContains(
                        'COUNT(DISTINCT tag_relation_id)',
                        array_map(fn (array $column) => (string)$column[1], $select->getPart(Select::COLUMNS))
                    );
                    return true;
                }),
                ['tag_id' => 7]
            )
            ->willReturn([['product_id' => '11', 'popularity' => '3'], ['product_id' => '12', 'popularity' => '5']]);

        $this->assertSame($this->collection, $this->collection->addPopularityFilter(['gteq' => 3]));

        $where = $this->collection->getSelect()->getPart(Select::WHERE);
        $this->assertSame("(e.entity_id IN('11', '12'))", end($where));
    }

    /**
     * addPopularityFilter() with no matching products filters on IN(0) (empty result)
     *
     * @return void
     */
    public function testAddPopularityFilterWithoutMatchesFiltersOnZero(): void
    {
        $this->connectionMock->expects($this->once())
            ->method('fetchAll')
            ->with($this->isInstanceOf(Select::class), ['tag_id' => null])
            ->willReturn([]);

        $this->collection->addPopularityFilter(['gteq' => 3]);

        $where = $this->collection->getSelect()->getPart(Select::WHERE);
        $this->assertSame('(e.entity_id IN(0))', end($where));
    }

    /**
     * addPopularity() left-joins the per-product popularity sub-select as "prelation"
     *
     * @return void
     */
    public function testAddPopularityJoinsPopularitySubSelect(): void
    {
        $this->assertSame($this->collection, $this->collection->addPopularity(7, 2));

        $from = $this->collection->getSelect()->getPart(Select::FROM);
        $this->assertSame(Select::LEFT_JOIN, $from['prelation']['joinType']);
        $this->assertSame(
            'prelation.product_id=e.entity_id AND prelation.store_id = 2',
            $from['prelation']['joinCondition']
        );
        $this->assertInstanceOf(Select::class, $from['prelation']['tableName']);
        $this->assertSame(['(relation.tag_id = 7)'], $from['prelation']['tableName']->getPart(Select::WHERE));
        $this->assertTrue($this->collection->getFlag('prelation'));
    }

    /**
     * addProductTags() attaches one active, popularity-aware tag collection per loaded product
     *
     * @return void
     */
    public function testAddProductTagsAttachesTagCollectionPerItem(): void
    {
        $this->collection->addCustomerFilter(5);
        $first = new DataObject(['entity_id' => 101]);
        $second = new DataObject(['entity_id' => 102]);
        $this->setLoadedItems([101 => $first, 102 => $second]);

        $tagCollections = [];
        $productFilters = [];
        $this->tagCollectionFactoryMock->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function () use (&$tagCollections, &$productFilters) {
                $tagCollection = $this->createMock(TagCollection::class);
                $tagCollection->expects($this->once())->method('addStoreFilter')->with(3)->willReturnSelf();
                $tagCollection->expects($this->once())->method('addPopularity')->with()->willReturnSelf();
                $tagCollection->expects($this->once())->method('addProductFilter')->willReturnCallback(
                    function ($productId) use ($tagCollection, &$productFilters) {
                        $productFilters[] = $productId;
                        return $tagCollection;
                    }
                );
                $tagCollection->expects($this->once())->method('addCustomerFilter')->with(5)->willReturnSelf();
                $tagCollection->expects($this->once())->method('setActiveFilter')->willReturnSelf();
                $tagCollection->expects($this->once())->method('load')->willReturnSelf();
                $tagCollections[] = $tagCollection;
                return $tagCollection;
            });

        $this->assertSame($this->collection, $this->collection->addProductTags(3));

        $this->assertSame([101, 102], $productFilters);
        $this->assertSame($tagCollections[0], $first->getProductTags());
        $this->assertSame($tagCollections[1], $second->getProductTags());
    }

    /**
     * addProductTags() without store id does not apply a store filter on the tag collections
     *
     * @return void
     */
    public function testAddProductTagsWithoutStoreSkipsStoreFilter(): void
    {
        $item = new DataObject(['entity_id' => 101]);
        $this->setLoadedItems([101 => $item]);

        $tagCollection = $this->createMock(TagCollection::class);
        $tagCollection->expects($this->never())->method('addStoreFilter');
        $tagCollection->method('addPopularity')->willReturnSelf();
        $tagCollection->method('addProductFilter')->willReturnSelf();
        $tagCollection->expects($this->once())->method('addCustomerFilter')->with(null)->willReturnSelf();
        $tagCollection->method('setActiveFilter')->willReturnSelf();
        $this->tagCollectionFactoryMock->expects($this->once())->method('create')->willReturn($tagCollection);

        $this->collection->addProductTags();

        $this->assertSame($tagCollection, $item->getProductTags());
    }

    /**
     * setDescOrder() registers a relation.tag_relation_id order (DESC by default)
     *
     * @return void
     */
    public function testSetDescOrder(): void
    {
        $this->assertSame($this->collection, $this->collection->setDescOrder());
        $this->assertSame(['relation.tag_relation_id' => 'DESC'], $this->readProperty('_orders'));
    }

    /**
     * setDescOrder($dir) honors the given direction
     *
     * @return void
     */
    public function testSetDescOrderWithDirection(): void
    {
        $this->collection->setDescOrder('ASC');
        $this->assertSame(['relation.tag_relation_id' => 'ASC'], $this->readProperty('_orders'));
    }

    /**
     * addGroupByTag() adds a relation.tag_relation_id grouping after the product grouping
     *
     * @return void
     */
    public function testAddGroupByTag(): void
    {
        $this->assertSame($this->collection, $this->collection->addGroupByTag());

        $this->assertSame(
            ['e.entity_id', 'relation.tag_relation_id'],
            $this->collection->getSelect()->getPart(Select::GROUP)
        );
    }

    /**
     * setRelationId() switches the collection id field to tag_relation_id
     *
     * @return void
     */
    public function testSetRelationId(): void
    {
        $this->assertSame($this->collection, $this->collection->setRelationId());
        $this->assertSame('tag_relation_id', $this->collection->getIdFieldName());
    }

    /**
     * Mark the collection as loaded with the given items (no database access)
     *
     * @param DataObject[] $items
     * @return void
     */
    private function setLoadedItems(array $items): void
    {
        (new \ReflectionProperty(Collection::class, '_items'))->setValue($this->collection, $items);
        (new \ReflectionProperty(Collection::class, '_isCollectionLoaded'))->setValue($this->collection, true);
    }

    /**
     * @param string $name
     * @return mixed
     */
    private function readProperty(string $name)
    {
        return (new \ReflectionProperty(Collection::class, $name))->getValue($this->collection);
    }
}
