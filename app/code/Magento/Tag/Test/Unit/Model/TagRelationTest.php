<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model;

use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tag\Model\AdminContext;
use Magento\Tag\Model\OperationIdGenerator;
use Magento\Tag\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Tag\Model\ResourceModel\Popular\CollectionFactory as PopularCollectionFactory;
use Magento\Tag\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Tag\Model\ResourceModel\Tag as TagResource;
use Magento\Tag\Model\ResourceModel\Tag\CollectionFactory as TagCollectionFactory;
use Magento\Tag\Model\ResourceModel\Tag\Relation as RelationResource;
use Magento\Tag\Model\Tag;
use Magento\Tag\Model\TagRelation;
use Magento\Tag\Model\TagRelationFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for \Magento\Tag\Model\TagRelation (port of Mage_Tag_Model_Tag_Relation)
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(TagRelation::class)]
class TagRelationTest extends TestCase
{
    private const OPERATION_ID = '3a8e1f20-7c4d-4b5a-9e6f-1a2b3c4d5e6f';

    /**
     * @var Context|MockObject
     */
    private $contextMock;

    /**
     * @var Registry|MockObject
     */
    private $registryMock;

    /**
     * @var RelationResource|MockObject
     */
    private $resourceMock;

    /**
     * @var LoggerInterface|MockObject
     */
    private $loggerMock;

    /**
     * @var OperationIdGenerator|MockObject
     */
    private $operationIdGeneratorMock;

    /**
     * @var TagRelation
     */
    private TagRelation $model;

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
        $this->contextMock = $this->createMock(Context::class);
        $this->registryMock = $this->createMock(Registry::class);
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

        $this->resourceMock = $this->createMock(RelationResource::class);
        $this->resourceMock->method('getIdFieldName')->willReturn('tag_relation_id');

        $this->model = new TagRelation(
            $this->contextMock,
            $this->registryMock,
            $this->loggerMock,
            $this->operationIdGeneratorMock,
            $this->resourceMock
        );
    }

    /**
     * @return void
     */
    public function testConstants(): void
    {
        $this->assertSame(1, TagRelation::STATUS_ACTIVE);
        $this->assertSame(0, TagRelation::STATUS_NOT_ACTIVE);
        $this->assertSame('tag_relation', TagRelation::ENTITY);
    }

    /**
     * loadByTagCustomer() sets the lookup keys (store included) and delegates to the resource model
     *
     * @return void
     */
    public function testLoadByTagCustomerWithStoreDelegatesToResource(): void
    {
        $row = [
            'tag_relation_id' => 7,
            'tag_id' => 10,
            'customer_id' => 5,
            'product_id' => 100,
            'store_id' => 1,
            'active' => 1,
        ];
        $this->resourceMock->expects($this->once())
            ->method('loadByTagCustomer')
            ->with($this->identicalTo($this->model))
            ->willReturnCallback(
                function (TagRelation $model) use ($row) {
                    $this->assertSame(100, $model->getProductId());
                    $this->assertSame(10, $model->getTagId());
                    $this->assertSame(5, $model->getCustomerId());
                    $this->assertTrue($model->hasStoreId());
                    $this->assertSame(1, $model->getStoreId());
                    $model->setData($row);
                    return $this->resourceMock;
                }
            );

        $this->assertSame($this->model, $this->model->loadByTagCustomer(100, 10, 5, 1));
        $this->assertSame(7, $this->model->getId());
        $this->assertSame(1, $this->model->getActive());
    }

    /**
     * A null store id is not set on the model (the resource then searches all stores)
     *
     * @return void
     */
    public function testLoadByTagCustomerWithoutStoreDoesNotSetStore(): void
    {
        $this->resourceMock->expects($this->once())
            ->method('loadByTagCustomer')
            ->willReturnCallback(
                function (TagRelation $model) {
                    $this->assertNull($model->getProductId());
                    $this->assertSame(10, $model->getTagId());
                    $this->assertSame(5, $model->getCustomerId());
                    $this->assertFalse($model->hasStoreId());
                    return $this->resourceMock;
                }
            );

        $this->model->loadByTagCustomer(null, 10, 5);
    }

    /**
     * getProductIds() asks the resource model once and caches the result in `product_ids`
     *
     * @return void
     */
    public function testGetProductIdsDelegatesOnceAndCaches(): void
    {
        $this->model->setTagId(10)->setStoreId(1)->setCustomerId(null);
        $this->resourceMock->expects($this->once())
            ->method('getProductIds')
            ->with($this->identicalTo($this->model))
            ->willReturn(['100', '200']);

        $this->assertSame(['100', '200'], $this->model->getProductIds());
        $this->assertSame(['100', '200'], $this->model->getProductIds());
        $this->assertSame(['100', '200'], $this->model->getData('product_ids'));
    }

    /**
     * Preset product ids are returned without querying
     *
     * @return void
     */
    public function testGetProductIdsReturnsPresetIds(): void
    {
        $this->model->setProductIds(['300']);
        $this->resourceMock->expects($this->never())->method('getProductIds');

        $this->assertSame(['300'], $this->model->getProductIds());
    }

    /**
     * getRelatedTagIds() asks the resource model once and caches the result in `related_tag_ids`
     *
     * @return void
     */
    public function testGetRelatedTagIdsDelegatesOnceAndCaches(): void
    {
        $this->model->setProductId(100);
        $this->resourceMock->expects($this->once())
            ->method('getRelatedTagIds')
            ->with($this->identicalTo($this->model))
            ->willReturn(['3', '7']);

        $this->assertSame(['3', '7'], $this->model->getRelatedTagIds());
        $this->assertSame(['3', '7'], $this->model->getRelatedTagIds());
        $this->assertSame(['3', '7'], $this->model->getData('related_tag_ids'));
    }

    /**
     * An empty related tag id list is cached too (only NULL triggers the lookup)
     *
     * @return void
     */
    public function testGetRelatedTagIdsCachesEmptyResult(): void
    {
        $this->resourceMock->expects($this->once())->method('getRelatedTagIds')->willReturn([]);

        $this->assertSame([], $this->model->getRelatedTagIds());
        $this->assertSame([], $this->model->getRelatedTagIds());
    }

    /**
     * Preset related tag ids are returned without querying
     *
     * @return void
     */
    public function testGetRelatedTagIdsReturnsPresetIds(): void
    {
        $this->model->setRelatedTagIds(['9']);
        $this->resourceMock->expects($this->never())->method('getRelatedTagIds');

        $this->assertSame(['9'], $this->model->getRelatedTagIds());
    }

    /**
     * getIdentities(): relation cache tag, plus tag and (scalar) product cache tags when set
     *
     * @param array $data
     * @param string[] $expected
     * @return void
     */
    #[DataProvider('identitiesDataProvider')]
    public function testGetIdentities(array $data, array $expected): void
    {
        $this->model->setData($data);

        $this->assertSame($expected, $this->model->getIdentities());
    }

    /**
     * @return array
     */
    public static function identitiesDataProvider(): array
    {
        return [
            'relation only' => [
                ['tag_relation_id' => 1],
                ['tag_relation_1'],
            ],
            'relation, tag and product' => [
                ['tag_relation_id' => 1, 'tag_id' => 10, 'product_id' => 100],
                ['tag_relation_1', 'tag_10', 'cat_p_100'],
            ],
            'tag without product' => [
                ['tag_relation_id' => 2, 'tag_id' => 10],
                ['tag_relation_2', 'tag_10'],
            ],
            'product without tag' => [
                ['tag_relation_id' => 2, 'product_id' => 100],
                ['tag_relation_2', 'cat_p_100'],
            ],
            'array product id is ignored' => [
                ['tag_relation_id' => 3, 'tag_id' => 10, 'product_id' => [100, 200]],
                ['tag_relation_3', 'tag_10'],
            ],
            'new relation (no id)' => [
                [],
                ['tag_relation_'],
            ],
        ];
    }

    /**
     * deactivate() delegates (tag id, customer id) to the resource model and logs one start + completion pair
     *
     * @return void
     */
    public function testDeactivateDelegatesToResourceAndLogs(): void
    {
        $this->model->setTagId(10)->setCustomerId(5)->setActive(1);
        $this->resourceMock->expects($this->once())
            ->method('deactivateRelations')
            ->with(10, 5)
            ->willReturn(['before_active' => [70 => 1, 71 => 0], 'affected_count' => 2]);

        $this->assertSame($this->model, $this->model->deactivate());

        $entries = $this->logsByOperation('relation.deactivate');
        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            $this->assertSame('info', $entry[0]);
            $this->assertSame('Magento_Tag', $entry[2]['module']);
            $this->assertSame(self::OPERATION_ID, $entry[2]['operation_id']);
            $this->assertSame(10, $entry[2]['tag_id']);
            $this->assertSame(5, $entry[2]['customer_id']);
        }
        $this->assertArrayNotHasKey('before_active', $entries[0][2]);
        $completion = $entries[1][2];
        $this->assertSame('success', $completion['outcome']);
        $this->assertSame(2, $completion['affected_count']);
        $this->assertSame([70, 71], $completion['relation_ids']);
        $this->assertSame([70 => 1, 71 => 0], $completion['before_active']);
        $this->assertSame(TagRelation::STATUS_NOT_ACTIVE, $completion['after_active']);
        $this->assertArrayHasKey('duration_ms', $completion);
    }

    /**
     * Nothing to deactivate: a single WARNING result with relation.deactivate.not_found and duration_ms
     *
     * @return void
     */
    public function testDeactivateNotFoundLogsWarning(): void
    {
        $this->model->setTagId(10)->setCustomerId(5);
        $this->resourceMock->method('deactivateRelations')
            ->willReturn(['before_active' => [], 'affected_count' => 0]);

        $this->model->deactivate();

        $entries = $this->logsByOperation('relation.deactivate');
        $this->assertCount(2, $entries);
        $this->assertSame('warning', $entries[1][0]);
        $this->assertSame('skipped', $entries[1][2]['outcome']);
        $this->assertSame('relation.deactivate.not_found', $entries[1][2]['error_code']);
        $this->assertArrayHasKey('duration_ms', $entries[1][2]);
    }

    /**
     * A failing deactivation is logged with an error_code and rethrown
     *
     * @return void
     */
    public function testDeactivateFailureIsLoggedAndRethrown(): void
    {
        $this->model->setTagId(10)->setCustomerId(5);
        $this->resourceMock->method('deactivateRelations')->willThrowException(new \RuntimeException('connection lost'));

        try {
            $this->model->deactivate();
            $this->fail('Exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('connection lost', $e->getMessage());
        }

        $errors = $this->logsByLevel('error');
        $this->assertCount(1, $errors);
        $this->assertSame('relation.deactivate', $errors[0][2]['operation']);
        $this->assertSame('failure', $errors[0][2]['outcome']);
        $this->assertSame('relation.deactivate.failed', $errors[0][2]['error_code']);
        $this->assertSame('connection lost', $errors[0][2]['exception']);
    }

    /**
     * addRelations() prepares the model exactly like M1 (added ids, tag id, customer null, store from tag)
     * and delegates to the resource model; start + completion are logged
     *
     * @return void
     */
    public function testAddRelationsPreparesDataAndDelegates(): void
    {
        $tag = $this->createTag(['tag_id' => 10, 'store' => 2]);
        $this->model->setCustomerId(99);

        $this->resourceMock->expects($this->once())
            ->method('addRelations')
            ->with($this->identicalTo($this->model))
            ->willReturnCallback(
                function (TagRelation $model) {
                    $this->assertSame([100, 200], $model->getAddedProductIds());
                    $this->assertSame(10, $model->getTagId());
                    $this->assertNull($model->getCustomerId());
                    $this->assertSame(2, $model->getStoreId());
                    $model->setData('inserted_product_ids', [200]);
                    $model->setData('deleted_product_ids', [300]);
                    return $this->resourceMock;
                }
            );

        $this->assertSame($this->model, $this->model->addRelations($tag, [100, 200]));

        $entries = $this->logsByOperation('relation.batch_save');
        $this->assertCount(2, $entries);
        $this->assertSame('info', $entries[0][0]);
        $this->assertSame(2, $entries[0][2]['requested_product_count']);
        $this->assertSame(10, $entries[0][2]['tag_id']);
        $this->assertSame(2, $entries[0][2]['store_id']);
        $this->assertSame(self::OPERATION_ID, $entries[0][2]['operation_id']);
        $this->assertSame('Magento_Tag', $entries[0][2]['module']);
        $completion = $entries[1][2];
        $this->assertSame('success', $completion['outcome']);
        $this->assertSame([200], $completion['inserted_product_ids']);
        $this->assertSame([300], $completion['deleted_product_ids']);
        $this->assertArrayHasKey('duration_ms', $completion);
    }

    /**
     * Without product ids an empty list is passed to the resource model (M1 default argument)
     *
     * @return void
     */
    public function testAddRelationsWithDefaultProductIds(): void
    {
        $tag = $this->createTag(['tag_id' => 10, 'store' => 1]);
        $this->resourceMock->expects($this->once())
            ->method('addRelations')
            ->willReturnCallback(
                function (TagRelation $model) {
                    $this->assertSame([], $model->getAddedProductIds());
                    return $this->resourceMock;
                }
            );

        $this->model->addRelations($tag);
    }

    /**
     * Non-array product ids are rejected before anything is touched (no silent "replace with empty set")
     *
     * @param mixed $productIds
     * @return void
     */
    #[DataProvider('invalidProductIdsProvider')]
    public function testAddRelationsRejectsNonArrayProductIds($productIds): void
    {
        $tag = $this->createTag(['tag_id' => 10, 'store' => 1]);
        $this->resourceMock->expects($this->never())->method('addRelations');

        $this->expectException(\InvalidArgumentException::class);
        $this->model->addRelations($tag, $productIds);
    }

    /**
     * @return array
     */
    public static function invalidProductIdsProvider(): array
    {
        return [
            'null' => [null],
            'scalar' => ['5'],
        ];
    }

    /**
     * A failing batch save is logged with a fingerprinted error_code and rethrown
     *
     * @return void
     */
    public function testAddRelationsFailureIsLoggedAndRethrown(): void
    {
        $tag = $this->createTag(['tag_id' => 10, 'store' => 1]);
        $this->resourceMock->method('addRelations')
            ->willThrowException(new DuplicateException('Duplicate entry'));

        try {
            $this->model->addRelations($tag, [100]);
            $this->fail('Exception expected');
        } catch (DuplicateException $e) {
            $this->assertSame('Duplicate entry', $e->getMessage());
        }

        $errors = $this->logsByLevel('error');
        $this->assertCount(1, $errors);
        $this->assertSame('relation.batch_save', $errors[0][2]['operation']);
        $this->assertSame('failure', $errors[0][2]['outcome']);
        $this->assertSame('relation.save.duplicate', $errors[0][2]['error_code']);
    }

    /**
     * Build a real Tag model with mocked collaborators
     *
     * @param array $data
     * @return Tag
     */
    private function createTag(array $data): Tag
    {
        $tagResource = $this->createMock(TagResource::class);
        $tagResource->method('getIdFieldName')->willReturn('tag_id');

        return new Tag(
            $this->contextMock,
            $this->registryMock,
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
            $tagResource,
            null,
            $data
        );
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

    /**
     * @param string $level
     * @return array[]
     */
    private function logsByLevel(string $level): array
    {
        return array_values(array_filter(
            $this->logEntries,
            fn (array $entry) => $entry[0] === $level
        ));
    }
}
