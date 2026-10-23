<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model\ResourceModel\Tag;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\Context as ModelContext;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Tag\Model\OperationIdGenerator;
use Magento\Tag\Model\ResourceModel\Tag\Relation;
use Magento\Tag\Model\TagRelation;
use Magento\Tag\Test\Unit\Model\ResourceModel\SqlSelectTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for \Magento\Tag\Model\ResourceModel\Tag\Relation (port of Mage_Tag_Model_Resource_Tag_Relation)
 *
 * The resource model runs on a non-connecting MySQL adapter that produces real Select objects; the fetch /
 * write methods are mocked so the built SQL parts, binds and written rows are asserted.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(Relation::class)]
class RelationTest extends TestCase
{
    use SqlSelectTrait;

    private const NOW = '2026-10-23 12:00:00';

    /**
     * @var Mysql|MockObject
     */
    private $connectionMock;

    /**
     * @var DateTime|MockObject
     */
    private $dateTimeMock;

    /**
     * @var Relation
     */
    private Relation $resource;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->connectionMock = $this->createSqlConnectionMock(
            ['fetchCol', 'fetchRow', 'fetchOne', 'fetchPairs', 'update', 'insertMultiple', 'delete']
        );

        $resourceConnectionMock = $this->createMock(ResourceConnection::class);
        $resourceConnectionMock->method('getConnection')->willReturn($this->connectionMock);
        $resourceConnectionMock->method('getTableName')->willReturnArgument(0);

        $contextMock = $this->createMock(Context::class);
        $contextMock->method('getResources')->willReturn($resourceConnectionMock);

        $this->dateTimeMock = $this->createMock(DateTime::class);
        $this->dateTimeMock->method('gmtDate')->willReturn(self::NOW);

        $this->resource = new Relation($contextMock, $this->dateTimeMock);
    }

    /**
     * The resource model is bound to tag_relation / tag_relation_id
     *
     * @return void
     */
    public function testMainTableAndIdField(): void
    {
        $this->assertSame('tag_relation', $this->resource->getMainTable());
        $this->assertSame('tag_relation_id', $this->resource->getIdFieldName());
    }

    /**
     * getRelatedTagIds() selects tag ids of the product(s), ordered by tag id
     *
     * @param mixed $productId
     * @param string $expectedWhere
     * @return void
     */
    #[DataProvider('relatedTagIdsDataProvider')]
    public function testGetRelatedTagIds($productId, string $expectedWhere): void
    {
        $model = $this->createModel(['product_id' => $productId]);
        $this->connectionMock->expects($this->once())
            ->method('fetchCol')
            ->with($this->callback(function (Select $select) use ($expectedWhere): bool {
                $this->assertSame('tag_relation', $select->getPart(Select::FROM)['tag_relation']['tableName']);
                $this->assertSame([['tag_relation', 'tag_id', null]], $select->getPart(Select::COLUMNS));
                $this->assertSame([$expectedWhere], $select->getPart(Select::WHERE));
                $this->assertSame([['tag_id', Select::SQL_ASC]], $select->getPart(Select::ORDER));
                return true;
            }))
            ->willReturn(['3', '7']);

        $this->assertSame(['3', '7'], $this->resource->getRelatedTagIds($model));
    }

    /**
     * @return array
     */
    public static function relatedTagIdsDataProvider(): array
    {
        return [
            'scalar product id' => [100, '(product_id IN(100))'],
            'array of product ids' => [[100, 200], '(product_id IN(100, 200))'],
        ];
    }

    /**
     * deactivateRelations() snapshots the before state, deactivates and reports both
     *
     * @return void
     */
    public function testDeactivateRelationsReturnsBeforeStateAndAffectedCount(): void
    {
        $this->connectionMock->expects($this->once())
            ->method('fetchPairs')
            ->with($this->callback(function (Select $select): bool {
                $this->assertSame(
                    [['tag_relation', 'tag_relation_id', null], ['tag_relation', 'active', null]],
                    $select->getPart(Select::COLUMNS)
                );
                $this->assertSame(['(tag_id = 10)', 'AND (customer_id = 5)'], $select->getPart(Select::WHERE));
                return true;
            }))
            ->willReturn(['21' => '1', '22' => '0']);
        $this->connectionMock->expects($this->once())
            ->method('update')
            ->with(
                'tag_relation',
                ['active' => TagRelation::STATUS_NOT_ACTIVE],
                ['tag_id = ?' => 10, 'customer_id = ?' => 5]
            )
            ->willReturn('1');

        $this->assertSame(
            ['before_active' => [21 => 1, 22 => 0], 'affected_count' => 1],
            $this->resource->deactivateRelations(10, 5)
        );
    }

    /**
     * deactivateRelations() with no matching relation reports an empty before state and 0 affected rows
     *
     * @return void
     */
    public function testDeactivateRelationsWithoutRelations(): void
    {
        $this->connectionMock->method('fetchPairs')->willReturn([]);
        $this->connectionMock->expects($this->once())->method('update')->willReturn(0);

        $this->assertSame(
            ['before_active' => [], 'affected_count' => 0],
            $this->resource->deactivateRelations(10, 5)
        );
    }

    /**
     * deactivate() runs the same update and returns the resource model
     *
     * @return void
     */
    public function testDeactivateDelegatesToDeactivateRelations(): void
    {
        $this->connectionMock->method('fetchPairs')->willReturn([]);
        $this->connectionMock->expects($this->once())
            ->method('update')
            ->with('tag_relation', ['active' => 0], ['tag_id = ?' => 10, 'customer_id = ?' => 5])
            ->willReturn(2);

        $this->assertSame($this->resource, $this->resource->deactivate(10, 5));
    }

    /**
     * loadByTagCustomer() with product and store binds all four filters and loads the fetched row
     *
     * @return void
     */
    public function testLoadByTagCustomerWithProductAndStore(): void
    {
        $model = $this->createModel(['tag_id' => 10, 'customer_id' => 5, 'product_id' => 100, 'store_id' => 1]);
        $row = ['tag_relation_id' => '21', 'tag_id' => '10', 'customer_id' => '5', 'active' => '1'];
        $this->connectionMock->expects($this->once())
            ->method('fetchRow')
            ->with(
                $this->callback(function (Select $select): bool {
                    $from = $select->getPart(Select::FROM);
                    $this->assertSame(['tag_relation', 'tag'], array_keys($from));
                    $this->assertSame(Select::INNER_JOIN, $from['tag']['joinType']);
                    $this->assertSame('tag.tag_id = tag_relation.tag_id', $from['tag']['joinCondition']);
                    $this->assertSame(
                        [
                            '(tag_relation.tag_id = :tag_id)',
                            'AND (customer_id = :customer_id)',
                            'AND (tag_relation.product_id = :product_id)',
                            'AND (tag_relation.store_id = :store_id)',
                        ],
                        $select->getPart(Select::WHERE)
                    );
                    return true;
                }),
                ['tag_id' => 10, 'customer_id' => 5, 'product_id' => 100, 'store_id' => 1]
            )
            ->willReturn($row);

        $this->assertSame($this->resource, $this->resource->loadByTagCustomer($model));
        $this->assertSame($row, $model->getData());
    }

    /**
     * loadByTagCustomer() without product / store only filters by tag and customer; no row empties the model
     *
     * @return void
     */
    public function testLoadByTagCustomerWithoutProductAndStoreAndNoRow(): void
    {
        $model = $this->createModel(['tag_id' => 10, 'customer_id' => 5]);
        $this->connectionMock->expects($this->once())
            ->method('fetchRow')
            ->with(
                $this->callback(function (Select $select): bool {
                    $this->assertSame(
                        ['(tag_relation.tag_id = :tag_id)', 'AND (customer_id = :customer_id)'],
                        $select->getPart(Select::WHERE)
                    );
                    return true;
                }),
                ['tag_id' => 10, 'customer_id' => 5]
            )
            ->willReturn(false);

        $this->resource->loadByTagCustomer($model);

        $this->assertSame([], $model->getData());
    }

    /**
     * loadByTagCustomer() does nothing without tag id or customer id
     *
     * @param array $data
     * @return void
     */
    #[DataProvider('incompleteTagCustomerDataProvider')]
    public function testLoadByTagCustomerRequiresTagAndCustomer(array $data): void
    {
        $model = $this->createModel($data);
        $this->connectionMock->expects($this->never())->method('fetchRow');

        $this->assertSame($this->resource, $this->resource->loadByTagCustomer($model));
        $this->assertSame($data, $model->getData());
    }

    /**
     * @return array
     */
    public static function incompleteTagCustomerDataProvider(): array
    {
        return [
            'no customer' => [['tag_id' => 10]],
            'no tag' => [['customer_id' => 5]],
        ];
    }

    /**
     * getProductIds() with every optional filter: customer, store and tag status (joins the tag table)
     *
     * @return void
     */
    public function testGetProductIdsWithAllFilters(): void
    {
        $model = $this->createModel(
            ['tag_id' => 10, 'customer_id' => 5, 'store_id' => 1, 'status_filter' => 1]
        );
        $this->connectionMock->expects($this->once())
            ->method('fetchCol')
            ->with(
                $this->callback(function (Select $select): bool {
                    $from = $select->getPart(Select::FROM);
                    $this->assertSame(['tag_relation', 'tag'], array_keys($from));
                    $this->assertSame('tag.tag_id = tag_relation.tag_id', $from['tag']['joinCondition']);
                    // M1 parity: the tag join keeps its default "tag.*" columns; fetchCol() reads product_id first
                    $this->assertSame(
                        [['tag_relation', 'product_id', null], ['tag', '*', null]],
                        $select->getPart(Select::COLUMNS)
                    );
                    $this->assertSame(
                        [
                            '(tag_relation.tag_id = :tag_id)',
                            'AND (tag_relation.customer_id = :customer_id)',
                            'AND (tag_relation.store_id = :store_id)',
                            'AND (tag.status = :t_status)',
                        ],
                        $select->getPart(Select::WHERE)
                    );
                    return true;
                }),
                ['tag_id' => 10, 'customer_id' => 5, 'store_id' => 1, 't_status' => 1]
            )
            ->willReturn(['100', '200']);

        $this->assertSame(['100', '200'], $this->resource->getProductIds($model));
    }

    /**
     * getProductIds() with only the tag id filters by tag only (no join)
     *
     * @return void
     */
    public function testGetProductIdsByTagOnly(): void
    {
        $model = $this->createModel(['tag_id' => 10]);
        $this->connectionMock->expects($this->once())
            ->method('fetchCol')
            ->with(
                $this->callback(function (Select $select): bool {
                    $this->assertSame(['tag_relation'], array_keys($select->getPart(Select::FROM)));
                    $this->assertSame(['(tag_relation.tag_id = :tag_id)'], $select->getPart(Select::WHERE));
                    return true;
                }),
                ['tag_id' => 10]
            )
            ->willReturn([]);

        $this->assertSame([], $this->resource->getProductIds($model));
    }

    /**
     * hasActiveProductRelation() checks for one active relation of tag/product (in the store when set)
     *
     * @return void
     */
    public function testHasActiveProductRelation(): void
    {
        $model = $this->createModel(['tag_id' => 10, 'product_id' => 100, 'store_id' => 1]);
        $this->connectionMock->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(function (Select $select): bool {
                    $this->assertSame(1, $select->getPart(Select::LIMIT_COUNT));
                    $this->assertCount(4, $select->getPart(Select::WHERE));
                    return true;
                }),
                ['tag_id' => 10, 'product_id' => 100, 'active' => TagRelation::STATUS_ACTIVE, 'store_id' => 1]
            )
            ->willReturn('21');

        $this->assertTrue($this->resource->hasActiveProductRelation($model));
    }

    /**
     * addRelations() inserts the new product ids and deletes the dropped ones for the model's store
     *
     * @return void
     */
    public function testAddRelationsInsertsAndDeletesDiff(): void
    {
        $model = $this->createModel(
            ['tag_id' => 10, 'store_id' => 1, 'customer_id' => null, 'added_product_ids' => ['100', '300']]
        );
        $this->connectionMock->expects($this->once())
            ->method('fetchCol')
            ->with(
                $this->callback(function (Select $select): bool {
                    $this->assertSame([['tag_relation', 'product_id', null]], $select->getPart(Select::COLUMNS));
                    $this->assertSame(
                        ['(tag_id = :tag_id)', 'AND (store_id = :store_id)'],
                        $select->getPart(Select::WHERE)
                    );
                    return true;
                }),
                ['tag_id' => 10, 'store_id' => 1]
            )
            ->willReturn(['100', '200']);
        $this->connectionMock->expects($this->once())
            ->method('insertMultiple')
            ->with('tag_relation', [
                [
                    'tag_id' => 10,
                    'store_id' => 1,
                    'product_id' => '300',
                    'customer_id' => null,
                    'created_at' => self::NOW,
                ],
            ])
            ->willReturn(1);
        $this->connectionMock->expects($this->once())
            ->method('delete')
            ->with('tag_relation', ['product_id IN (?)' => [1 => '200'], 'store_id = ?' => 1])
            ->willReturn(1);

        $this->assertSame($this->resource, $this->resource->addRelations($model));
        $this->assertSame(['300'], $model->getData('inserted_product_ids'));
        $this->assertSame(['200'], $model->getData('deleted_product_ids'));
    }

    /**
     * addRelations() with an unchanged product set writes nothing
     *
     * @return void
     */
    public function testAddRelationsWithoutChangesWritesNothing(): void
    {
        $model = $this->createModel(['tag_id' => 10, 'store_id' => 1, 'added_product_ids' => ['100']]);
        $this->connectionMock->method('fetchCol')->willReturn(['100']);
        $this->connectionMock->expects($this->never())->method('insertMultiple');
        $this->connectionMock->expects($this->never())->method('delete');
        $this->dateTimeMock->expects($this->never())->method('gmtDate');

        $this->resource->addRelations($model);

        $this->assertSame([], $model->getData('inserted_product_ids'));
        $this->assertSame([], $model->getData('deleted_product_ids'));
    }

    /**
     * addRelations() with an empty product set deletes every existing relation of the tag in the store
     *
     * @return void
     */
    public function testAddRelationsWithEmptyListDeletesAll(): void
    {
        $model = $this->createModel(['tag_id' => 10, 'store_id' => 2, 'added_product_ids' => []]);
        $this->connectionMock->method('fetchCol')->willReturn(['100', '200']);
        $this->connectionMock->expects($this->never())->method('insertMultiple');
        $this->connectionMock->expects($this->once())
            ->method('delete')
            ->with('tag_relation', ['product_id IN (?)' => ['100', '200'], 'store_id = ?' => 2]);

        $this->resource->addRelations($model);

        $this->assertSame(['100', '200'], $model->getData('deleted_product_ids'));
    }

    /**
     * addRelations() rejects non-array added product ids before touching the database
     *
     * @param mixed $addedIds
     * @return void
     */
    #[DataProvider('nonArrayProductIdsDataProvider')]
    public function testAddRelationsRejectsNonArrayAddedIds($addedIds): void
    {
        $model = $this->createModel(['tag_id' => 10, 'store_id' => 1, 'added_product_ids' => $addedIds]);
        $this->connectionMock->expects($this->never())->method('fetchCol');
        $this->connectionMock->expects($this->never())->method('insertMultiple');
        $this->connectionMock->expects($this->never())->method('delete');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag relation product ids must be an array.');

        $this->resource->addRelations($model);
    }

    /**
     * @return array
     */
    public static function nonArrayProductIdsDataProvider(): array
    {
        return [
            'null' => [null],
            'string' => ['100,200'],
            'int' => [100],
        ];
    }

    /**
     * Build a real relation model carrying the given data (resource = the resource under test)
     *
     * @param array $data
     * @return TagRelation
     */
    private function createModel(array $data): TagRelation
    {
        return new TagRelation(
            $this->createMock(ModelContext::class),
            $this->createMock(Registry::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(OperationIdGenerator::class),
            $this->resource,
            null,
            $data
        );
    }
}
