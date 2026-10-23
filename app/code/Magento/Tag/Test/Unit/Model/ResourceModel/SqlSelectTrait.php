<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model\ResourceModel;

use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Platform\Quote;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Select\ColumnsRenderer;
use Magento\Framework\DB\Select\DistinctRenderer;
use Magento\Framework\DB\Select\FromRenderer;
use Magento\Framework\DB\Select\GroupRenderer;
use Magento\Framework\DB\Select\HavingRenderer;
use Magento\Framework\DB\Select\LimitRenderer;
use Magento\Framework\DB\Select\OrderRenderer;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\DB\Select\UnionRenderer;
use Magento\Framework\DB\Select\WhereRenderer;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Builds a partially mocked MySQL adapter whose select() returns real Select objects
 *
 * The adapter never connects: _connect() is a no-op and _quote() quotes values in PHP, so the Select parts
 * (and the rendered SQL) produced by the code under test can be asserted without a database.
 */
trait SqlSelectTrait
{
    /**
     * Create a MySQL adapter partial mock producing real Select objects
     *
     * @param string[] $methods additional adapter methods to mock (fetch*, update, insertMultiple, delete...)
     * @return Mysql|MockObject
     */
    private function createSqlConnectionMock(array $methods = []): MockObject
    {
        $connection = $this->createPartialMock(
            Mysql::class,
            array_values(array_unique(array_merge(['select', '_connect', '_quote'], $methods)))
        );
        $renderer = $this->createSqlSelectRenderer();
        $connection->method('select')->willReturnCallback(
            static fn () => new Select($connection, $renderer)
        );
        $connection->method('_quote')->willReturnCallback(
            static function ($value): string {
                if (is_int($value) || is_float($value)) {
                    return (string)$value;
                }
                return "'" . addcslashes((string)$value, "\000\n\r\\'\"\032") . "'";
            }
        );

        return $connection;
    }

    /**
     * Real select renderer (same parts as the production DI configuration)
     *
     * @return SelectRenderer
     */
    private function createSqlSelectRenderer(): SelectRenderer
    {
        $quote = new Quote();
        return new SelectRenderer([
            'distinct' => ['renderer' => new DistinctRenderer(), 'sort' => 100, 'part' => 'distinct'],
            'columns' => ['renderer' => new ColumnsRenderer($quote), 'sort' => 200, 'part' => 'columns'],
            'union' => ['renderer' => new UnionRenderer(), 'sort' => 300, 'part' => 'union'],
            'from' => ['renderer' => new FromRenderer($quote), 'sort' => 400, 'part' => 'from'],
            'where' => ['renderer' => new WhereRenderer(), 'sort' => 500, 'part' => 'where'],
            'group' => ['renderer' => new GroupRenderer($quote), 'sort' => 600, 'part' => 'group'],
            'having' => ['renderer' => new HavingRenderer(), 'sort' => 700, 'part' => 'having'],
            'order' => ['renderer' => new OrderRenderer($quote), 'sort' => 800, 'part' => 'order'],
            'limit' => ['renderer' => new LimitRenderer(), 'sort' => 900, 'part' => 'limitcount'],
        ]);
    }
}
