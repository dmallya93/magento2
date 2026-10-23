<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model\ResourceModel\Tag;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Tag\Model\OperationIdGenerator;
use Magento\Tag\Model\ResourceModel\Tag as TagResource;
use Magento\Tag\Model\ResourceModel\Tag\Collection;
use Magento\Tag\Test\Unit\Model\ResourceModel\SqlSelectTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for \Magento\Tag\Model\ResourceModel\Tag\Collection (port of Mage_Tag_Model_Resource_Tag_Collection)
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
     * addPopularity() left-joins tag_relation and tag_summary, selects summary popularity and groups by tag
     *
     * @return void
     */
    public function testAddPopularityJoinsRelationAndSummary(): void
    {
        $this->assertSame($this->collection, $this->collection->addPopularity());
        $select = $this->collection->getSelect();

        $from = $select->getPart(Select::FROM);
        $this->assertSame(['main_table', 'relation', 'summary'], array_keys($from));

        $this->assertSame(Select::LEFT_JOIN, $from['relation']['joinType']);
        $this->assertSame('tag_relation', $from['relation']['tableName']);
        $this->assertSame('main_table.tag_id = relation.tag_id', $from['relation']['joinCondition']);

        $this->assertSame(Select::LEFT_JOIN, $from['summary']['joinType']);
        $this->assertSame('tag_summary', $from['summary']['tableName']);
        $this->assertSame(
            'relation.tag_id = summary.tag_id AND relation.store_id = summary.store_id',
            $from['summary']['joinCondition']
        );

        $this->assertContains(['summary', 'popularity', null], $select->getPart(Select::COLUMNS));
        $this->assertNotContains('relation', array_column($select->getPart(Select::COLUMNS), 0));
        $this->assertSame(['main_table.tag_id'], $select->getPart(Select::GROUP));
        $this->assertNull($select->getPart(Select::LIMIT_COUNT));
        // M1 parity: setFlag('popularity') stores a NULL value, so the flag exists but is falsy
        $this->assertTrue($this->collection->hasFlag('popularity'));
        $this->assertNull($this->collection->getFlag('popularity'));
        $this->assertTrue($this->readProperty('_useAnalyticFunction'));
    }

    /**
     * addPopularity($limit) also limits the select
     *
     * @return void
     */
    public function testAddPopularityWithLimit(): void
    {
        $this->collection->addPopularity(10);

        $this->assertSame(10, $this->collection->getSelect()->getPart(Select::LIMIT_COUNT));
    }

    /**
     * A second addPopularity() call joins again: M1 stores the "popularity" flag as NULL (setFlag() without a
     * value), so the guard never trips and the duplicate "relation" correlation name is rejected by Zend_Db
     *
     * @return void
     */
    public function testSecondAddPopularityCallJoinsAgainAsInM1(): void
    {
        $this->collection->addPopularity();

        $this->expectException(\Zend_Db_Select_Exception::class);
        $this->expectExceptionMessage("You cannot define a correlation name 'relation' more than once");
        $this->collection->addPopularity(10);
    }

    /**
     * addPopularity() is skipped entirely when the popularity flag was already set
     *
     * @return void
     */
    public function testAddPopularitySkippedWhenFlagPreset(): void
    {
        $this->collection->setFlag('popularity', true);
        $this->collection->addPopularity(10);

        $this->assertSame(['main_table'], array_keys($this->collection->getSelect()->getPart(Select::FROM)));
        $this->assertFalse($this->readProperty('_useAnalyticFunction'));
    }

    /**
     * addPopularity() renders to the M1 SQL shape
     *
     * @return void
     */
    public function testAddPopularityRenderedSql(): void
    {
        $sql = (string)$this->collection->addPopularity(5)->getSelect()->assemble();

        $this->assertStringContainsString(
            'LEFT JOIN `tag_relation` AS `relation` ON main_table.tag_id = relation.tag_id',
            $sql
        );
        $this->assertStringContainsString(
            'LEFT JOIN `tag_summary` AS `summary` ON relation.tag_id = summary.tag_id '
            . 'AND relation.store_id = summary.store_id',
            $sql
        );
        $this->assertStringContainsString('`summary`.`popularity`', $sql);
        $this->assertStringContainsString('GROUP BY `main_table`.`tag_id`', $sql);
        $this->assertStringEndsWith('LIMIT 5', $sql);
    }

    /**
     * joinRel() + addTagGroup() (used by Tag::productEventAggregate) build the relation join and grouping
     *
     * @return void
     */
    public function testJoinRelAndAddTagGroup(): void
    {
        $this->collection->joinRel()->addTagGroup();
        $select = $this->collection->getSelect();

        $from = $select->getPart(Select::FROM);
        $this->assertSame(Select::LEFT_JOIN, $from['relation']['joinType']);
        $this->assertSame('main_table.tag_id=relation.tag_id', $from['relation']['joinCondition']);
        $this->assertTrue($this->collection->getFlag('relation'));
        $this->assertSame(['main_table.tag_id'], $select->getPart(Select::GROUP));
    }

    /**
     * Popularity filter is a HAVING on COUNT(relation.tag_relation_id) only when the relation is joined
     *
     * @param bool $joinRelation
     * @param string $expectedPart
     * @return void
     */
    #[DataProvider('popularityFilterDataProvider')]
    public function testPopularityFieldFilter(bool $joinRelation, string $expectedPart): void
    {
        if ($joinRelation) {
            $this->collection->joinRel();
        } else {
            $this->collection->addSummary(1);
        }
        $this->collection->addFieldToFilter('popularity', ['gt' => 2]);
        $select = $this->collection->getSelect();

        $other = $expectedPart === Select::HAVING ? Select::WHERE : Select::HAVING;
        $this->assertCount(1, $select->getPart($expectedPart));
        $this->assertStringContainsString(
            $joinRelation ? 'COUNT(relation.tag_relation_id) > 2' : 'summary.popularity > 2',
            $select->getPart($expectedPart)[0]
        );
        $this->assertSame([], $select->getPart($other));
    }

    /**
     * @return array
     */
    public static function popularityFilterDataProvider(): array
    {
        return [
            'relation joined -> HAVING' => [true, Select::HAVING],
            'summary joined -> WHERE' => [false, Select::WHERE],
        ];
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
