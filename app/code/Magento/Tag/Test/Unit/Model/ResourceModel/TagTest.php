<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context as ModelContext;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Model\ResourceModel\Db\TransactionManagerInterface;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tag\Model\AdminContext;
use Magento\Tag\Model\OperationIdGenerator;
use Magento\Tag\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Tag\Model\ResourceModel\Popular\CollectionFactory as PopularCollectionFactory;
use Magento\Tag\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Tag\Model\ResourceModel\Tag as TagResource;
use Magento\Tag\Model\ResourceModel\Tag\CollectionFactory as TagCollectionFactory;
use Magento\Tag\Model\Tag;
use Magento\Tag\Model\TagRelationFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for \Magento\Tag\Model\ResourceModel\Tag (port of Mage_Tag_Model_Resource_Tag)
 *
 * Covers merge-on-save, multibyte-safe 255-char truncation and the read-only duplicate-name check.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(TagResource::class)]
class TagTest extends TestCase
{
    private const OPERATION_ID = '5b7e9c11-2d3f-4a6b-8c9d-0e1f2a3b4c5d';

    /**
     * @var AdapterInterface|MockObject
     */
    private $connectionMock;

    /**
     * @var Select|MockObject
     */
    private $selectMock;

    /**
     * @var AdminContext|MockObject
     */
    private $adminContextMock;

    /**
     * @var LoggerInterface|MockObject
     */
    private $loggerMock;

    /**
     * @var OperationIdGenerator|MockObject
     */
    private $operationIdGeneratorMock;

    /**
     * @var TagResource
     */
    private TagResource $resource;

    /**
     * Captured log entries: [level, message, context]
     *
     * @var array[]
     */
    private array $logEntries = [];

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->logEntries = [];

        $this->selectMock = $this->createMock(Select::class);
        foreach (['from', 'where', 'join', 'joinLeft', 'limit', 'group', 'order'] as $method) {
            $this->selectMock->method($method)->willReturnSelf();
        }

        $this->connectionMock = $this->createMock(AdapterInterface::class);
        $this->connectionMock->method('select')->willReturn($this->selectMock);

        $resourceConnectionMock = $this->createMock(ResourceConnection::class);
        $resourceConnectionMock->method('getConnection')->willReturn($this->connectionMock);
        $resourceConnectionMock->method('getTableName')->willReturnArgument(0);

        $contextMock = $this->createMock(Context::class);
        $contextMock->method('getResources')->willReturn($resourceConnectionMock);
        $transactionManagerMock = $this->createMock(TransactionManagerInterface::class);
        $transactionManagerMock->method('start')->willReturnArgument(0);
        $contextMock->method('getTransactionManager')->willReturn($transactionManagerMock);

        $this->adminContextMock = $this->createMock(AdminContext::class);
        $this->adminContextMock->method('isAdminContext')->willReturn(false);

        $this->operationIdGeneratorMock = $this->createMock(OperationIdGenerator::class);
        $this->operationIdGeneratorMock->method('getOperationId')->willReturn(self::OPERATION_ID);

        $this->loggerMock = $this->createMock(LoggerInterface::class);
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
            $this->loggerMock->method($level)->willReturnCallback(
                function ($message, array $context = []) use ($level): void {
                    $this->logEntries[] = [$level, (string)$message, $context];
                }
            );
        }

        $this->resource = new TagResource(
            $contextMock,
            new StringUtils(),
            $this->adminContextMock,
            $this->loggerMock,
            $this->operationIdGeneratorMock
        );
    }

    /**
     * A NEW tag saved as APPROVED whose name matches an existing PENDING tag reuses the pending tag id
     *
     * @return void
     */
    public function testBeforeSaveMergesNewApprovedTagIntoExistingPendingTag(): void
    {
        $tag = $this->createTag(['name' => 'summer', 'status' => Tag::STATUS_APPROVED, 'admin_user_id' => 3]);
        $this->connectionMock->expects($this->once())
            ->method('fetchRow')
            ->with($this->selectMock, ['name' => 'summer'])
            ->willReturn(['tag_id' => '7', 'name' => 'summer', 'status' => (string)Tag::STATUS_PENDING]);

        $this->invokeBeforeSave($tag);

        $this->assertSame('7', $tag->getId());
        $this->assertSame(Tag::STATUS_APPROVED, $tag->getStatus());

        $merges = $this->logsByOperation('tag.merge');
        $this->assertCount(1, $merges);
        [$level, , $context] = $merges[0];
        $this->assertSame('info', $level);
        $this->assertSame('Magento_Tag', $context['module']);
        $this->assertSame(self::OPERATION_ID, $context['operation_id']);
        $this->assertSame('7', $context['merged_into_tag_id']);
        $this->assertSame('summer', $context['tag_name']);
        $this->assertSame(Tag::STATUS_PENDING, $context['previous_status']);
        $this->assertSame(Tag::STATUS_APPROVED, $context['new_status']);
        $this->assertSame(3, $context['admin_user_id']);
    }

    /**
     * A merged save is an update of the pending tag: the duplicate-name check must not run for it
     *
     * @return void
     */
    public function testMergedTagSkipsDuplicateCheckInAfterSave(): void
    {
        $tag = $this->createTag(['name' => 'summer', 'status' => Tag::STATUS_APPROVED]);
        $tag->isObjectNew(true);
        $this->connectionMock->method('fetchRow')
            ->willReturn(['tag_id' => '7', 'name' => 'summer', 'status' => (string)Tag::STATUS_PENDING]);
        $this->connectionMock->expects($this->never())->method('fetchCol');

        $this->invokeBeforeSave($tag);
        $this->invokeAfterSave($tag);

        $this->assertSame([], $this->logsByOperation('tag.name.duplicate_detected'));
    }

    /**
     * An existing tag (has id) is never merged and no lookup is done
     *
     * @return void
     */
    public function testBeforeSaveDoesNotMergeExistingTag(): void
    {
        $tag = $this->createTag(['tag_id' => 12, 'name' => 'summer', 'status' => Tag::STATUS_APPROVED]);
        $this->connectionMock->expects($this->never())->method('fetchRow');

        $this->invokeBeforeSave($tag);

        $this->assertSame(12, $tag->getId());
        $this->assertSame([], $this->logsByOperation('tag.merge'));
    }

    /**
     * A new tag that is not APPROVED (customer path creates PENDING tags) is never merged
     *
     * @param int $status
     * @return void
     */
    #[DataProvider('notApprovedStatusDataProvider')]
    public function testBeforeSaveDoesNotMergeNewNotApprovedTag(int $status): void
    {
        $tag = $this->createTag(['name' => 'summer', 'status' => $status]);
        $this->connectionMock->expects($this->never())->method('fetchRow');

        $this->invokeBeforeSave($tag);

        $this->assertNull($tag->getId());
        $this->assertSame([], $this->logsByOperation('tag.merge'));
    }

    /**
     * @return array
     */
    public static function notApprovedStatusDataProvider(): array
    {
        return [
            'pending' => [Tag::STATUS_PENDING],
            'disabled' => [Tag::STATUS_DISABLED],
        ];
    }

    /**
     * A new APPROVED tag is not merged when the same-name tag is not PENDING, or there is none
     *
     * @param array|false $existingRow
     * @return void
     */
    #[DataProvider('notMergeableRowDataProvider')]
    public function testBeforeSaveDoesNotMergeWhenExistingTagIsNotPending($existingRow): void
    {
        $tag = $this->createTag(['name' => 'summer', 'status' => Tag::STATUS_APPROVED]);
        $this->connectionMock->expects($this->once())
            ->method('fetchRow')
            ->willReturn($existingRow);

        $this->invokeBeforeSave($tag);

        $this->assertNull($tag->getId());
        $this->assertSame([], $this->logsByOperation('tag.merge'));
    }

    /**
     * @return array
     */
    public static function notMergeableRowDataProvider(): array
    {
        return [
            'existing approved' => [['tag_id' => '7', 'name' => 'summer', 'status' => (string)Tag::STATUS_APPROVED]],
            'existing disabled' => [['tag_id' => '7', 'name' => 'summer', 'status' => (string)Tag::STATUS_DISABLED]],
            'no existing tag' => [false],
        ];
    }

    /**
     * loadByName() truncates over-length multibyte names to exactly 255 characters before the lookup
     *
     * @param string $char
     * @return void
     */
    #[DataProvider('multibyteCharDataProvider')]
    public function testLoadByNameTruncatesMultibyteNameAndLogs(string $char): void
    {
        $name = str_repeat($char, 300);
        $boundName = null;
        $this->connectionMock->expects($this->once())
            ->method('fetchRow')
            ->willReturnCallback(
                function ($select, array $bind) use (&$boundName) {
                    $boundName = $bind['name'];
                    return ['tag_id' => '21', 'name' => $bind['name'], 'status' => '1'];
                }
            );
        $model = new DataObject();

        $this->assertNull($this->resource->loadByName($model, $name));

        $this->assertIsString($boundName);
        $this->assertSame(255, mb_strlen($boundName, 'UTF-8'));
        $this->assertTrue(mb_check_encoding($boundName, 'UTF-8'), 'Truncated name must be valid UTF-8');
        $this->assertSame(str_repeat($char, 255), $boundName);
        $this->assertSame('21', $model->getData('tag_id'));

        $warnings = $this->logsByOperation('tag.name.truncated');
        $this->assertCount(1, $warnings);
        [$level, , $context] = $warnings[0];
        $this->assertSame('warning', $level);
        $this->assertSame('Magento_Tag', $context['module']);
        $this->assertSame(self::OPERATION_ID, $context['operation_id']);
        $this->assertSame('tag.name.truncated', $context['error_code']);
        $this->assertSame('load_by_name', $context['call_site']);
        $this->assertSame(300, $context['original_length']);
        $this->assertSame(255, $context['stored_length']);
        $this->assertSame('21', $context['tag_id']);
    }

    /**
     * @return array
     */
    public static function multibyteCharDataProvider(): array
    {
        return [
            'two-byte umlaut' => ['ä'],
            'four-byte emoji' => ["\u{1F600}"],
            'three-byte CJK' => ['标'],
        ];
    }

    /**
     * Names of at most 255 characters are looked up unchanged and no warning is logged
     *
     * @return void
     */
    public function testLoadByNameDoesNotTruncateNameUpTo255Chars(): void
    {
        $name = str_repeat("\u{1F600}", 255);
        $this->connectionMock->expects($this->once())
            ->method('fetchRow')
            ->with($this->selectMock, ['name' => $name])
            ->willReturn(false);
        $model = new DataObject(['tag_id' => 99]);

        $this->assertNull($this->resource->loadByName($model, $name));

        $this->assertSame([], $model->getData(), 'Model data is reset when nothing is found (M1 parity)');
        $this->assertSame([], $this->logsByOperation('tag.name.truncated'));
    }

    /**
     * An empty name returns false without a query (M1 parity)
     *
     * @return void
     */
    public function testLoadByNameWithEmptyNameReturnsFalse(): void
    {
        $this->connectionMock->expects($this->never())->method('fetchRow');

        $this->assertFalse($this->resource->loadByName(new DataObject(), ''));
    }

    /**
     * _beforeSave() truncates over-length multibyte names to exactly 255 characters
     *
     * @param string $char
     * @return void
     */
    #[DataProvider('multibyteCharDataProvider')]
    public function testBeforeSaveTruncatesMultibyteNameAndLogs(string $char): void
    {
        $tag = $this->createTag(['tag_id' => 33, 'name' => str_repeat($char, 300), 'status' => Tag::STATUS_PENDING]);

        $this->invokeBeforeSave($tag);

        $stored = $tag->getName();
        $this->assertSame(255, mb_strlen($stored, 'UTF-8'));
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'), 'Truncated name must be valid UTF-8');
        $this->assertSame(str_repeat($char, 255), $stored);

        $warnings = $this->logsByOperation('tag.name.truncated');
        $this->assertCount(1, $warnings);
        [$level, , $context] = $warnings[0];
        $this->assertSame('warning', $level);
        $this->assertSame('Magento_Tag', $context['module']);
        $this->assertSame(self::OPERATION_ID, $context['operation_id']);
        $this->assertSame('before_save', $context['call_site']);
        $this->assertSame(300, $context['original_length']);
        $this->assertSame(255, $context['stored_length']);
        $this->assertSame(33, $context['tag_id']);
    }

    /**
     * Merge lookup of a new APPROVED tag uses the truncated name and truncation is logged once
     *
     * @return void
     */
    public function testBeforeSaveMergeLookupUsesTruncatedName(): void
    {
        $tag = $this->createTag(['name' => str_repeat('ä', 300), 'status' => Tag::STATUS_APPROVED]);
        $this->connectionMock->expects($this->once())
            ->method('fetchRow')
            ->with($this->selectMock, ['name' => str_repeat('ä', 255)])
            ->willReturn(['tag_id' => '8', 'name' => str_repeat('ä', 255), 'status' => '0']);

        $this->invokeBeforeSave($tag);

        $this->assertSame('8', $tag->getId());
        $this->assertCount(1, $this->logsByOperation('tag.name.truncated'));
        $this->assertCount(1, $this->logsByOperation('tag.merge'));
    }

    /**
     * Names of at most 255 characters are saved unchanged and no warning is logged
     *
     * @return void
     */
    public function testBeforeSaveDoesNotTruncateNameUpTo255Chars(): void
    {
        $name = str_repeat('ä', 255);
        $tag = $this->createTag(['tag_id' => 33, 'name' => $name, 'status' => Tag::STATUS_PENDING]);

        $this->invokeBeforeSave($tag);

        $this->assertSame($name, $tag->getName());
        $this->assertSame([], $this->logsByOperation('tag.name.truncated'));
    }

    /**
     * A new tag with a pre-existing same-name row logs tag.name.duplicate_detected and the save is not blocked
     *
     * @return void
     */
    public function testAfterSaveDetectsDuplicateNameForNewTag(): void
    {
        $tag = $this->createTag(['tag_id' => 40, 'name' => 'summer', 'status' => Tag::STATUS_PENDING]);
        $tag->isObjectNew(true);
        $this->connectionMock->expects($this->once())
            ->method('fetchCol')
            ->with($this->selectMock, ['name' => 'summer', 'tag_id' => 40])
            ->willReturn(['39']);

        $this->assertSame($this->resource, $this->invokeAfterSave($tag));

        $warnings = $this->logsByOperation('tag.name.duplicate_detected');
        $this->assertCount(1, $warnings);
        [$level, , $context] = $warnings[0];
        $this->assertSame('warning', $level);
        $this->assertSame('Magento_Tag', $context['module']);
        $this->assertSame(self::OPERATION_ID, $context['operation_id']);
        $this->assertSame('tag.name.duplicate_detected', $context['error_code']);
        $this->assertSame(40, $context['tag_id']);
        $this->assertSame('summer', $context['tag_name']);
        $this->assertSame(['39'], $context['duplicate_tag_ids']);
    }

    /**
     * No other same-name row -> no warning
     *
     * @return void
     */
    public function testAfterSaveNoDuplicateNoWarning(): void
    {
        $tag = $this->createTag(['tag_id' => 40, 'name' => 'summer', 'status' => Tag::STATUS_PENDING]);
        $tag->isObjectNew(true);
        $this->connectionMock->expects($this->once())->method('fetchCol')->willReturn([]);

        $this->invokeAfterSave($tag);

        $this->assertSame([], $this->logsByOperation('tag.name.duplicate_detected'));
    }

    /**
     * Existing (not new) tags do not run the duplicate check
     *
     * @return void
     */
    public function testAfterSaveSkipsDuplicateCheckForExistingTag(): void
    {
        $tag = $this->createTag(['tag_id' => 40, 'name' => 'summer', 'status' => Tag::STATUS_APPROVED]);
        $tag->isObjectNew(false);
        $this->connectionMock->expects($this->never())->method('fetchCol');

        $this->invokeAfterSave($tag);

        $this->assertSame([], $this->logsByOperation('tag.name.duplicate_detected'));
    }

    /**
     * A DB failure inside the read-only duplicate check never breaks the save
     *
     * @return void
     */
    public function testAfterSaveDuplicateCheckFailureDoesNotBreakSave(): void
    {
        $tag = $this->createTag(['tag_id' => 40, 'name' => 'summer', 'status' => Tag::STATUS_PENDING]);
        $tag->isObjectNew(true);
        $this->connectionMock->method('fetchCol')
            ->willThrowException(
                new \RuntimeException('SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server')
            );

        $this->assertSame($this->resource, $this->invokeAfterSave($tag));

        $this->assertSame([], $this->logsByOperation('tag.name.duplicate_detected'));
    }

    /**
     * A tag delete rejected inside the delete transaction is logged as tag.delete start + failure and rethrown
     *
     * @return void
     */
    public function testDeleteFailureIsLoggedAndRethrown(): void
    {
        $tag = $this->createMock(Tag::class);
        $tag->method('getId')->willReturn(7);
        $tag->method('getData')->willReturnCallback(fn ($key = '') => $key === 'name' ? 'red' : null);
        $tag->method('beforeDelete')
            ->willThrowException(new LocalizedException(__('Cannot complete this operation from non-admin area.')));

        try {
            $this->resource->delete($tag);
            $this->fail('Exception expected');
        } catch (LocalizedException $e) {
            $this->assertSame('Cannot complete this operation from non-admin area.', $e->getMessage());
        }

        $entries = $this->logsByOperation('tag.delete');
        $this->assertCount(2, $entries);
        $this->assertSame(['info', 'Tag delete started'], [$entries[0][0], $entries[0][1]]);
        $this->assertSame(7, $entries[0][2]['tag_id']);
        $this->assertSame(self::OPERATION_ID, $entries[0][2]['operation_id']);
        $this->assertSame('error', $entries[1][0]);
        $this->assertSame('failure', $entries[1][2]['outcome']);
        $this->assertSame('tag.delete.failed', $entries[1][2]['error_code']);
        $this->assertArrayHasKey('duration_ms', $entries[1][2]);
    }

    /**
     * decrementProducts() logs start + completion with the affected row count
     *
     * @return void
     */
    public function testDecrementProductsLogsStartAndResult(): void
    {
        $this->connectionMock->expects($this->once())->method('update')->willReturn(2);

        $this->assertSame(2, $this->resource->decrementProducts([3, 4]));

        $entries = $this->logsByOperation('tag.decrement_products');
        $this->assertCount(2, $entries);
        $this->assertSame([3, 4], $entries[0][2]['tag_ids']);
        $this->assertSame('success', $entries[1][2]['outcome']);
        $this->assertSame(2, $entries[1][2]['affected_count']);
        $this->assertArrayHasKey('duration_ms', $entries[1][2]);
    }

    /**
     * decrementProducts() with no tag ids does nothing and logs nothing (M1 early return)
     *
     * @return void
     */
    public function testDecrementProductsWithNoTagsIsNoop(): void
    {
        $this->connectionMock->expects($this->never())->method('update');

        $this->assertSame(0, $this->resource->decrementProducts([]));
        $this->assertSame([], $this->logsByOperation('tag.decrement_products'));
    }

    /**
     * A failing summary rewrite is logged as tag.aggregate failure with an error_code and rethrown
     *
     * @return void
     */
    public function testAggregateFailureIsLoggedAndRethrown(): void
    {
        $this->connectionMock->method('fetchAll')->willReturn([]);
        $this->connectionMock->method('fetchAssoc')->willReturn([]);
        $this->connectionMock->method('delete')->willThrowException(new \RuntimeException('lock wait timeout'));

        try {
            $this->resource->aggregate($this->createTag(['tag_id' => 9]));
            $this->fail('Exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('lock wait timeout', $e->getMessage());
        }

        $entries = $this->logsByOperation('tag.aggregate');
        $this->assertCount(2, $entries);
        $this->assertSame('Tag summary aggregation started', $entries[0][1]);
        $this->assertSame('error', $entries[1][0]);
        $this->assertSame('tag.aggregate.failed', $entries[1][2]['error_code']);
        $this->assertArrayHasKey('duration_ms', $entries[1][2]);
    }

    /**
     * A successful aggregation logs start + completion with outcome and duration_ms
     *
     * @return void
     */
    public function testAggregateLogsStartAndResult(): void
    {
        $this->connectionMock->method('fetchAll')->willReturn([]);
        $this->connectionMock->method('fetchAssoc')->willReturn([]);
        $this->connectionMock->expects($this->once())->method('delete');

        $this->resource->aggregate($this->createTag(['tag_id' => 9]));

        $entries = $this->logsByOperation('tag.aggregate');
        $this->assertCount(2, $entries);
        $this->assertSame('success', $entries[1][2]['outcome']);
        $this->assertSame(0, $entries[1][2]['summary_rows']);
        $this->assertArrayHasKey('duration_ms', $entries[1][2]);
    }

    /**
     * Build a real Tag model with mocked collaborators (resource = the resource under test)
     *
     * @param array $data
     * @return Tag
     */
    private function createTag(array $data): Tag
    {
        return new Tag(
            $this->createMock(ModelContext::class),
            $this->createMock(Registry::class),
            $this->createPartialMock(TagRelationFactory::class, ['create']),
            $this->createPartialMock(TagCollectionFactory::class, ['create']),
            $this->createPartialMock(ProductCollectionFactory::class, ['create']),
            $this->createPartialMock(CustomerCollectionFactory::class, ['create']),
            $this->createPartialMock(PopularCollectionFactory::class, ['create']),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(UrlInterface::class),
            $this->createMock(DateTime::class),
            $this->createMock(LoggerInterface::class),
            $this->operationIdGeneratorMock,
            $this->createMock(AdminContext::class),
            $this->resource,
            null,
            $data
        );
    }

    /**
     * @param Tag $tag
     * @return mixed
     */
    private function invokeBeforeSave(Tag $tag)
    {
        $method = new \ReflectionMethod(TagResource::class, '_beforeSave');
        return $method->invoke($this->resource, $tag);
    }

    /**
     * @param Tag $tag
     * @return mixed
     */
    private function invokeAfterSave(Tag $tag)
    {
        $method = new \ReflectionMethod(TagResource::class, '_afterSave');
        return $method->invoke($this->resource, $tag);
    }

    /**
     * @param string $operation
     * @return array[]
     */
    private function logsByOperation(string $operation): array
    {
        return array_values(array_filter(
            $this->logEntries,
            fn (array $entry) => ($entry[2]['operation'] ?? null) === $operation
        ));
    }
}
