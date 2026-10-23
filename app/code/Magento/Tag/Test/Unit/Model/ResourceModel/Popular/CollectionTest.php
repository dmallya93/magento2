<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model\ResourceModel\Popular;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Tag\Model\OperationIdGenerator;
use Magento\Tag\Model\ResourceModel\Popular\Collection;
use Magento\Tag\Model\ResourceModel\Tag as TagResource;
use Magento\Tag\Model\Tag;
use Magento\Tag\Test\Unit\Model\ResourceModel\SqlSelectTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for \Magento\Tag\Model\ResourceModel\Popular\Collection
 * (port of Mage_Tag_Model_Resource_Popular_Collection)
 *
 * The collection is built with its real constructor on a mocked tag resource model and a non-connecting
 * MySQL adapter that produces real Select objects, so the resulting SQL parts are asserted.
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
     * @var TagResource|MockObject
     */
    private $resourceMock;

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

        $this->resourceMock = $this->createMock(TagResource::class);
        $this->resourceMock->method('getConnection')->willReturn($this->connectionMock);
        $this->resourceMock->method('getMainTable')->willReturn('tag');
        $this->resourceMock->method('getTable')->willReturnArgument(0);

        $this->collection = new Collection(
            $this->createMock(EntityFactoryInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(FetchStrategyInterface::class),
            $this->createMock(ManagerInterface::class),
            $this->createMock(OperationIdGenerator::class),
            null,
            $this->resourceMock
        );
    }

    /**
     * The initial select reads the main tag table
     *
     * @return void
     */
    public function testInitialSelectUsesTagMainTable(): void
    {
        $from = $this->collection->getSelect()->getPart(Select::FROM);

        $this->assertSame(['main_table'], array_keys($from));
        $this->assertSame('tag', $from['main_table']['tableName']);
    }

    /**
     * addStatusFilter() adds a main_table.status WHERE condition and is fluent
     *
     * @return void
     */
    public function testAddStatusFilterAddsWhereOnMainTableStatus(): void
    {
        $this->assertSame($this->collection, $this->collection->addStatusFilter(Tag::STATUS_APPROVED));

        $this->assertSame(
            ['(main_table.status = 1)'],
            $this->collection->getSelect()->getPart(Select::WHERE)
        );
    }

    /**
     * Several addStatusFilter() calls are AND-ed (M1 behavior: no reset)
     *
     * @return void
     */
    public function testAddStatusFilterCallsAreAndCombined(): void
    {
        $this->collection->addStatusFilter(Tag::STATUS_APPROVED)
            ->addStatusFilter(Tag::STATUS_PENDING);

        $this->assertSame(
            ['(main_table.status = 1)', 'AND (main_table.status = 0)'],
            $this->collection->getSelect()->getPart(Select::WHERE)
        );
    }

    /**
     * joinFields() rebuilds the select from tag_summary joined to approved tags, ordered by popularity DESC
     *
     * @return void
     */
    public function testJoinFieldsBuildsPopularSelect(): void
    {
        $this->collection->joinFields(3);
        $select = $this->collection->getSelect();

        $from = $select->getPart(Select::FROM);
        $this->assertSame(['tag_summary', 'tag'], array_keys($from));
        $this->assertSame(Select::INNER_JOIN, $from['tag']['joinType']);
        $this->assertSame('tag.tag_id = tag_summary.tag_id AND tag.status = 1', $from['tag']['joinCondition']);
        $this->assertSame(
            ['(tag_summary.store_id = 3)', 'AND (tag_summary.products > 0)'],
            $select->getPart(Select::WHERE)
        );
        $this->assertSame([['popularity', Select::SQL_DESC]], $select->getPart(Select::ORDER));
    }

    /**
     * getSelectCountSql() wraps the select (without ORDER / LIMIT) in a COUNT(popularity) sub-query
     *
     * @return void
     */
    public function testGetSelectCountSqlWrapsSelectWithoutOrderAndLimit(): void
    {
        $this->collection->joinFields(1);
        $this->collection->limit(5);
        $this->collection->getSelect()->limitPage(2, 5);

        $countSelect = $this->collection->getSelectCountSql();

        $this->assertInstanceOf(Select::class, $countSelect);
        $this->assertNotSame($this->collection->getSelect(), $countSelect);
        $columns = $countSelect->getPart(Select::COLUMNS);
        $this->assertCount(1, $columns);
        $this->assertSame('a', $columns[0][0]);
        $this->assertSame('COUNT(popularity)', (string)$columns[0][1]);

        $from = $countSelect->getPart(Select::FROM);
        $this->assertSame(['a'], array_keys($from));
        $inner = $from['a']['tableName'];
        $this->assertInstanceOf(Select::class, $inner);
        $this->assertNotSame($this->collection->getSelect(), $inner);
        $this->assertSame([], $inner->getPart(Select::ORDER));
        $this->assertNull($inner->getPart(Select::LIMIT_COUNT));
        $this->assertNull($inner->getPart(Select::LIMIT_OFFSET));
        $this->assertSame(
            ['(tag_summary.store_id = 1)', 'AND (tag_summary.products > 0)'],
            $inner->getPart(Select::WHERE)
        );

        // The collection's own select keeps its ORDER / LIMIT
        $this->assertSame([['popularity', Select::SQL_DESC]], $this->collection->getSelect()->getPart(Select::ORDER));
        $this->assertSame(5, $this->collection->getSelect()->getPart(Select::LIMIT_COUNT));

        $sql = $countSelect->assemble();
        $this->assertStringStartsWith('SELECT COUNT(popularity) FROM (SELECT ', $sql);
        $this->assertStringEndsWith(') AS `a`', $sql);
        $this->assertStringNotContainsString('ORDER BY', $sql);
        $this->assertStringNotContainsString('LIMIT', $sql);
    }

    /**
     * getSelectCountSql() renders the pending addFilter() filters into the counted sub-query
     *
     * @return void
     */
    public function testGetSelectCountSqlRendersPendingFilters(): void
    {
        $this->collection->addStatusFilter(Tag::STATUS_APPROVED);
        $this->collection->addFilter('main_table.name', 'summer');

        $inner = $this->collection->getSelectCountSql()->getPart(Select::FROM)['a']['tableName'];

        $where = $inner->getPart(Select::WHERE);
        $this->assertCount(2, $where);
        $this->assertSame('(main_table.status = 1)', $where[0]);
        $this->assertStringContainsString("'summer'", $where[1]);
    }
}
