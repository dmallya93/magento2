<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model\Logging;

use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Tag\Model\Logging\ErrorCodeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shared exception-to-error_code fingerprinting
 */
#[CoversClass(ErrorCodeResolver::class)]
class ErrorCodeResolverTest extends TestCase
{
    /**
     * Each exception shape maps to the expected fingerprint slug
     *
     * @param \Throwable $exception
     * @param string $prefix
     * @param string $duplicateSlug
     * @param string $expected
     * @return void
     */
    #[DataProvider('exceptionDataProvider')]
    public function testResolve(\Throwable $exception, string $prefix, string $duplicateSlug, string $expected): void
    {
        $this->assertSame($expected, ErrorCodeResolver::resolve($exception, $prefix, $duplicateSlug));
    }

    /**
     * @return array
     */
    public static function exceptionDataProvider(): array
    {
        return [
            'duplicate exception' => [
                new DuplicateException('x'), 'relation.save', 'duplicate', 'relation.save.duplicate',
            ],
            'already exists' => [
                new AlreadyExistsException(__('x')), 'tag.save', 'unique_violation', 'tag.save.unique_violation',
            ],
            'nested fk message' => [
                new \RuntimeException('wrapper', 0, new \Exception('a foreign key constraint fails')),
                'relation.save', 'duplicate', 'relation.save.fk_violation',
            ],
            'mysql 1452' => [
                new \Exception('SQLSTATE[23000]: 1452 Cannot add'), 'tag.save', 'unique_violation', 'tag.save.fk_violation',
            ],
            'duplicate entry message' => [
                new \Exception("Duplicate entry 'x' for key"), 'relation.save', 'duplicate', 'relation.save.duplicate',
            ],
            'mysql 1062' => [
                new \Exception('error 1062'), 'tag.save', 'unique_violation', 'tag.save.unique_violation',
            ],
            'unrelated' => [
                new \Exception('connection lost'), 'relation.save', 'duplicate', 'relation.save.failed',
            ],
        ];
    }
}
