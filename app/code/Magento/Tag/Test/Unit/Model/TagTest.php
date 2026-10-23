<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model;

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
 * Unit tests for \Magento\Tag\Model\Tag (port of Mage_Tag_Model_Tag)
 *
 * saveRelation() is exercised against an in-memory fake of the tag_relation table (the relation resource
 * model mock answers loadByTagCustomer()/getProductIds()/hasActiveProductRelation() with the same filters
 * the SQL uses), so each of the 9 decision-table paths of Project Spec DD10 is asserted on both the
 * returned result code and the persisted change.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(Tag::class)]
class TagTest extends TestCase
{
    private const OPERATION_ID = '9d2c7a51-4b3e-4f60-8a1d-2e5f6a7b8c90';
    private const NOW = '2026-10-22 10:00:00';
    private const TAG_ID = 10;
    private const PRODUCT_ID = 100;
    private const OTHER_PRODUCT_ID = 200;
    private const CUSTOMER_ID = 5;
    private const OTHER_CUSTOMER_ID = 6;
    private const STORE_ID = 1;

    /**
     * @var Context|MockObject
     */
    private $contextMock;

    /**
     * @var Registry|MockObject
     */
    private $registryMock;

    /**
     * @var TagResource|MockObject
     */
    private $tagResourceMock;

    /**
     * @var RelationResource|MockObject
     */
    private $relationResourceMock;

    /**
     * @var TagRelationFactory|MockObject
     */
    private $tagRelationFactoryMock;

    /**
     * @var DateTime|MockObject
     */
    private $dateTimeMock;

    /**
     * @var LoggerInterface|MockObject
     */
    private $loggerMock;

    /**
     * @var OperationIdGenerator|MockObject
     */
    private $operationIdGeneratorMock;

    /**
     * @var Tag
     */
    private Tag $tag;

    /**
     * Fake tag_relation rows
     *
     * @var array[]
     */
    private array $relationRows = [];

    /**
     * When true, the fake hasActiveProductRelation() throws (diagnostic check failure)
     *
     * @var bool
     */
    private bool $activeRelationCheckFails = false;

    /**
     * Snapshots of relation models passed to the relation resource save()
     *
     * @var array[]
     */
    private array $savedRelations = [];

    /**
     * Snapshots of tag data passed to the tag resource save()
     *
     * @var array[]
     */
    private array $savedTags = [];

    /**
     * Ordered persistence events ('tag_save' / 'relation_save')
     *
     * @var string[]
     */
    private array $persistenceSequence = [];

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
        $this->relationRows = [];
        $this->savedRelations = [];
        $this->savedTags = [];
        $this->persistenceSequence = [];
        $this->logEntries = [];

        $this->contextMock = $this->createMock(Context::class);
        $this->registryMock = $this->createMock(Registry::class);
        $this->dateTimeMock = $this->createMock(DateTime::class);
        $this->dateTimeMock->method('gmtDate')->willReturn(self::NOW);

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

        $this->tagResourceMock = $this->createMock(TagResource::class);
        $this->tagResourceMock->method('getIdFieldName')->willReturn('tag_id');
        $this->tagResourceMock->method('save')->willReturnCallback(
            function (Tag $tag) {
                $this->savedTags[] = $tag->getData();
                $this->persistenceSequence[] = 'tag_save';
                return $this->tagResourceMock;
            }
        );

        $this->relationResourceMock = $this->createMock(RelationResource::class);
        $this->relationResourceMock->method('getIdFieldName')->willReturn('tag_relation_id');
        $this->relationResourceMock->method('loadByTagCustomer')->willReturnCallback(
            fn (TagRelation $model) => $this->fakeLoadByTagCustomer($model)
        );
        $this->relationResourceMock->method('getProductIds')->willReturnCallback(
            fn (TagRelation $model) => $this->fakeGetProductIds($model)
        );
        $this->relationResourceMock->method('hasActiveProductRelation')->willReturnCallback(
            fn (TagRelation $model) => $this->fakeHasActiveProductRelation($model)
        );
        $this->relationResourceMock->method('save')->willReturnCallback(
            function (TagRelation $model) {
                $this->savedRelations[] = $model->getData();
                $this->persistenceSequence[] = 'relation_save';
                return $this->relationResourceMock;
            }
        );

        $relationLogger = $this->createMock(LoggerInterface::class);
        $this->tagRelationFactoryMock = $this->createPartialMock(TagRelationFactory::class, ['create']);
        $this->tagRelationFactoryMock->method('create')->willReturnCallback(
            fn () => new TagRelation(
                $this->contextMock,
                $this->registryMock,
                $relationLogger,
                $this->operationIdGeneratorMock,
                $this->relationResourceMock
            )
        );

        $this->tag = new Tag(
            $this->contextMock,
            $this->registryMock,
            $this->tagRelationFactoryMock,
            $this->createPartialMock(TagCollectionFactory::class, ['create']),
            $this->createPartialMock(ProductCollectionFactory::class, ['create']),
            $this->createPartialMock(CustomerCollectionFactory::class, ['create']),
            $this->createPartialMock(PopularCollectionFactory::class, ['create']),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(UrlInterface::class),
            $this->dateTimeMock,
            $this->loggerMock,
            $this->operationIdGeneratorMock,
            $this->createMock(AdminContext::class),
            $this->tagResourceMock
        );
    }

    /**
     * Status constants and add-status constants keep the M1 values
     *
     * @return void
     */
    public function testStatusConstants(): void
    {
        $this->assertSame(-1, Tag::STATUS_DISABLED);
        $this->assertSame(0, Tag::STATUS_PENDING);
        $this->assertSame(1, Tag::STATUS_APPROVED);

        $this->assertSame(Tag::STATUS_APPROVED, $this->tag->getApprovedStatus());
        $this->assertSame(Tag::STATUS_PENDING, $this->tag->getPendingStatus());
        $this->assertSame(Tag::STATUS_DISABLED, $this->tag->getDisabledStatus());
    }

    /**
     * @return void
     */
    public function testAddStatusConstants(): void
    {
        $this->assertSame('success', Tag::ADD_STATUS_SUCCESS);
        $this->assertSame('new', Tag::ADD_STATUS_NEW);
        $this->assertSame('exist', Tag::ADD_STATUS_EXIST);
        $this->assertSame('rejected', Tag::ADD_STATUS_REJECTED);
        $this->assertSame('tag', Tag::ENTITY);
        $this->assertSame('tag', Tag::CACHE_TAG);
    }

    /**
     * The nine decision path slugs are exactly the ones of Project Spec DD14
     *
     * @return void
     */
    public function testDecisionPathSlugConstants(): void
    {
        $this->assertSame(
            [
                'approved.product_untagged',
                'approved.customer_unlinked',
                'approved.customer_link_inactive',
                'approved.customer_link_active',
                'pending.customer_unlinked',
                'pending.customer_link_inactive',
                'pending.customer_link_active',
                'disabled.customer_already_linked',
                'disabled.reopen_to_pending',
            ],
            [
                Tag::DECISION_APPROVED_PRODUCT_UNTAGGED,
                Tag::DECISION_APPROVED_CUSTOMER_UNLINKED,
                Tag::DECISION_APPROVED_CUSTOMER_LINK_INACTIVE,
                Tag::DECISION_APPROVED_CUSTOMER_LINK_ACTIVE,
                Tag::DECISION_PENDING_CUSTOMER_UNLINKED,
                Tag::DECISION_PENDING_CUSTOMER_LINK_INACTIVE,
                Tag::DECISION_PENDING_CUSTOMER_LINK_ACTIVE,
                Tag::DECISION_DISABLED_CUSTOMER_ALREADY_LINKED,
                Tag::DECISION_DISABLED_REOPEN_TO_PENDING,
            ]
        );
    }

    /**
     * loadByName() delegates to the resource model with the model and the raw name
     *
     * @return void
     */
    public function testLoadByNameDelegatesToResource(): void
    {
        $this->tagResourceMock->expects($this->once())
            ->method('loadByName')
            ->with($this->identicalTo($this->tag), 'summer sale')
            ->willReturnCallback(
                function (Tag $model) {
                    $model->setData(['tag_id' => 42, 'name' => 'summer sale', 'status' => Tag::STATUS_APPROVED]);
                    return null;
                }
            );

        $this->assertSame($this->tag, $this->tag->loadByName('summer sale'));
        $this->assertSame(42, $this->tag->getId());
        $this->assertSame('summer sale', $this->tag->getName());
    }

    /**
     * aggregate() delegates to the resource model and returns the model
     *
     * @return void
     */
    public function testAggregateDelegatesToResource(): void
    {
        $this->tag->setData(['tag_id' => self::TAG_ID, 'store' => self::STORE_ID]);
        $this->tagResourceMock->expects($this->once())
            ->method('aggregate')
            ->with($this->identicalTo($this->tag))
            ->willReturnArgument(0);

        $this->assertSame($this->tag, $this->tag->aggregate());
    }

    /**
     * Path 1: APPROVED, nobody linked this product in this store -> new relation, `success`
     *
     * @return void
     */
    public function testSaveRelationApprovedProductUntagged(): void
    {
        // Relations of other products / other stores must not count.
        $this->relationRows = [
            $this->row(1, self::OTHER_CUSTOMER_ID, self::OTHER_PRODUCT_ID, self::STORE_ID, 1),
            $this->row(2, self::OTHER_CUSTOMER_ID, self::PRODUCT_ID, 2, 1),
        ];
        $this->initTag(Tag::STATUS_APPROVED);

        $result = $this->saveRelation();

        $this->assertSame(Tag::ADD_STATUS_SUCCESS, $result);
        $this->assertRelationCreated();
        $this->assertTagNotSaved();
        $this->assertDecisionLogged(
            'approved.product_untagged',
            'success',
            'relation_created',
            Tag::STATUS_APPROVED,
            false
        );
        $this->assertNoOpWarning(null);
        $this->assertNoReopenWarning();
    }

    /**
     * Path 2: APPROVED, product linked by someone else, this customer has no link -> new relation, `exist`
     *
     * @return void
     */
    public function testSaveRelationApprovedCustomerUnlinked(): void
    {
        $this->relationRows = [
            $this->row(1, self::OTHER_CUSTOMER_ID, self::PRODUCT_ID, self::STORE_ID, 1),
        ];
        $this->initTag(Tag::STATUS_APPROVED);

        $result = $this->saveRelation();

        $this->assertSame(Tag::ADD_STATUS_EXIST, $result);
        $this->assertRelationCreated();
        $this->assertTagNotSaved();
        $this->assertDecisionLogged(
            'approved.customer_unlinked',
            'exist',
            'relation_created',
            Tag::STATUS_APPROVED,
            false
        );
        $this->assertNoReopenWarning();
    }

    /**
     * Path 3: APPROVED, product linked, this customer's link is inactive -> reactivate relation, `exist`
     *
     * @return void
     */
    public function testSaveRelationApprovedCustomerLinkInactive(): void
    {
        $this->relationRows = [
            $this->row(1, self::OTHER_CUSTOMER_ID, self::PRODUCT_ID, self::STORE_ID, 1),
            $this->row(2, self::CUSTOMER_ID, self::PRODUCT_ID, self::STORE_ID, 0),
        ];
        $this->initTag(Tag::STATUS_APPROVED);

        $result = $this->saveRelation();

        $this->assertSame(Tag::ADD_STATUS_EXIST, $result);
        $this->assertRelationReactivated(2);
        $this->assertTagNotSaved();
        $this->assertDecisionLogged(
            'approved.customer_link_inactive',
            'exist',
            'relation_reactivated',
            Tag::STATUS_APPROVED,
            false
        );
        $this->assertNoReopenWarning();
    }

    /**
     * Path 4: APPROVED, product linked, this customer's link is active -> nothing persisted, `exist`
     *
     * @return void
     */
    public function testSaveRelationApprovedCustomerLinkActive(): void
    {
        $this->relationRows = [
            $this->row(2, self::CUSTOMER_ID, self::PRODUCT_ID, self::STORE_ID, 1),
        ];
        $this->initTag(Tag::STATUS_APPROVED);

        $result = $this->saveRelation();

        $this->assertSame(Tag::ADD_STATUS_EXIST, $result);
        $this->assertNothingPersisted();
        $this->assertDecisionLogged(
            'approved.customer_link_active',
            'exist',
            'none',
            Tag::STATUS_APPROVED,
            false
        );
        $this->assertNoOpWarning('relation.save.duplicate', Tag::ADD_STATUS_EXIST);
        $this->assertNoReopenWarning();
    }

    /**
     * APPROVED tag whose only relations on the product are inactive: the product still counts as tagged
     * (M1 ignores the `active` flag), so a new customer gets `exist` instead of `success`.
     *
     * @return void
     */
    public function testSaveRelationApprovedWithOnlyInactiveLinksReturnsExist(): void
    {
        $this->relationRows = [
            $this->row(1, self::OTHER_CUSTOMER_ID, self::PRODUCT_ID, self::STORE_ID, 0),
        ];
        $this->initTag(Tag::STATUS_APPROVED);

        $result = $this->saveRelation();

        $this->assertSame(Tag::ADD_STATUS_EXIST, $result);
        $this->assertRelationCreated();
        $this->assertTagNotSaved();
        $this->assertDecisionLogged(
            'approved.customer_unlinked',
            'exist',
            'relation_created',
            Tag::STATUS_APPROVED,
            true
        );
    }

    /**
     * Path 5: PENDING, this customer has no link -> new relation, `new`
     *
     * @return void
     */
    public function testSaveRelationPendingCustomerUnlinked(): void
    {
        $this->relationRows = [
            $this->row(1, self::OTHER_CUSTOMER_ID, self::PRODUCT_ID, self::STORE_ID, 1),
        ];
        $this->initTag(Tag::STATUS_PENDING);

        $result = $this->saveRelation();

        $this->assertSame(Tag::ADD_STATUS_NEW, $result);
        $this->assertRelationCreated();
        $this->assertTagNotSaved();
        $this->assertDecisionLogged(
            'pending.customer_unlinked',
            'new',
            'relation_created',
            Tag::STATUS_PENDING,
            false
        );
        $this->assertNoReopenWarning();
    }

    /**
     * Path 6: PENDING, this customer's link is inactive -> reactivate relation, `new`
     *
     * @return void
     */
    public function testSaveRelationPendingCustomerLinkInactive(): void
    {
        $this->relationRows = [
            $this->row(2, self::CUSTOMER_ID, self::PRODUCT_ID, self::STORE_ID, 0),
        ];
        $this->initTag(Tag::STATUS_PENDING);

        $result = $this->saveRelation();

        $this->assertSame(Tag::ADD_STATUS_NEW, $result);
        $this->assertRelationReactivated(2);
        $this->assertTagNotSaved();
        $this->assertDecisionLogged(
            'pending.customer_link_inactive',
            'new',
            'relation_reactivated',
            Tag::STATUS_PENDING,
            false
        );
        $this->assertNoReopenWarning();
    }

    /**
     * Path 7: PENDING, this customer's link is active -> nothing persisted, `new`
     *
     * @return void
     */
    public function testSaveRelationPendingCustomerLinkActive(): void
    {
        $this->relationRows = [
            $this->row(2, self::CUSTOMER_ID, self::PRODUCT_ID, self::STORE_ID, 1),
        ];
        $this->initTag(Tag::STATUS_PENDING);

        $result = $this->saveRelation();

        $this->assertSame(Tag::ADD_STATUS_NEW, $result);
        $this->assertNothingPersisted();
        $this->assertDecisionLogged(
            'pending.customer_link_active',
            'new',
            'none',
            Tag::STATUS_PENDING,
            false
        );
        $this->assertNoOpWarning('relation.save.duplicate', Tag::ADD_STATUS_NEW);
        $this->assertNoReopenWarning();
    }

    /**
     * Path 8: DISABLED, this customer already linked this product (active or inactive) -> nothing, `rejected`
     *
     * @param int $active
     * @return void
     */
    #[DataProvider('linkActiveFlagDataProvider')]
    public function testSaveRelationDisabledCustomerAlreadyLinked(int $active): void
    {
        $this->relationRows = [
            $this->row(2, self::CUSTOMER_ID, self::PRODUCT_ID, self::STORE_ID, $active),
        ];
        $this->initTag(Tag::STATUS_DISABLED);

        $result = $this->saveRelation();

        $this->assertSame(Tag::ADD_STATUS_REJECTED, $result);
        $this->assertNothingPersisted();
        $this->assertSame(Tag::STATUS_DISABLED, $this->tag->getStatus());
        $this->assertDecisionLogged(
            'disabled.customer_already_linked',
            'rejected',
            'none',
            Tag::STATUS_DISABLED,
            false
        );
        $this->assertNoOpWarning('relation.save.rejected_disabled', Tag::ADD_STATUS_REJECTED);
        $this->assertNoReopenWarning();
    }

    /**
     * @return array
     */
    public static function linkActiveFlagDataProvider(): array
    {
        return [
            'inactive link' => [0],
            'active link' => [1],
        ];
    }

    /**
     * Path 9: DISABLED, this customer has no link to this product -> tag set to PENDING + new relation, `new`
     *
     * @return void
     */
    public function testSaveRelationDisabledReopenToPending(): void
    {
        // Another customer's link on the product does not matter for the DISABLED branch.
        $this->relationRows = [
            $this->row(1, self::OTHER_CUSTOMER_ID, self::PRODUCT_ID, self::STORE_ID, 1),
        ];
        $this->initTag(Tag::STATUS_DISABLED);

        $result = $this->saveRelation();

        $this->assertSame(Tag::ADD_STATUS_NEW, $result);
        $this->assertTagReopenedAndRelationCreated();
        $this->assertDecisionLogged(
            'disabled.reopen_to_pending',
            'new',
            'tag_reopened_and_relation_created',
            Tag::STATUS_DISABLED,
            false
        );
        $this->assertReopenWarning();
    }

    /**
     * DISABLED -> PENDING flip also happens when the original tagger tags a DIFFERENT product
     * (the DISABLED branch is keyed on customer + product, not on who created the tag)
     *
     * @return void
     */
    public function testSaveRelationDisabledReopensWhenSameCustomerTagsDifferentProduct(): void
    {
        $this->relationRows = [
            $this->row(2, self::CUSTOMER_ID, self::OTHER_PRODUCT_ID, self::STORE_ID, 1),
        ];
        $this->initTag(Tag::STATUS_DISABLED);
        $this->tag->setFirstCustomerId(self::CUSTOMER_ID);

        $result = $this->saveRelation();

        $this->assertSame(Tag::ADD_STATUS_NEW, $result);
        $this->assertTagReopenedAndRelationCreated();
        $this->assertDecisionLogged(
            'disabled.reopen_to_pending',
            'new',
            'tag_reopened_and_relation_created',
            Tag::STATUS_DISABLED,
            false
        );
        $this->assertReopenWarning();
    }

    /**
     * A failing inactive-links diagnostic does not change the decision, logs a WARNING and reports null
     *
     * @return void
     */
    public function testInactiveLinksCheckFailureIsLoggedAndReportedAsUnknown(): void
    {
        $this->relationRows = [
            $this->row(1, self::OTHER_CUSTOMER_ID, self::PRODUCT_ID, self::STORE_ID, 1),
        ];
        $this->activeRelationCheckFails = true;
        $this->initTag(Tag::STATUS_APPROVED);

        $this->assertSame(Tag::ADD_STATUS_EXIST, $this->saveRelation());

        $this->assertRelationCreated();
        $checks = $this->logsByOperation('relation.inactive_links_check');
        $this->assertCount(1, $checks);
        $this->assertSame('warning', $checks[0][0]);
        $this->assertSame('relation.inactive_links_check_failed', $checks[0][2]['error_code']);
        $decide = $this->logsByOperation('relation.decide');
        $this->assertCount(1, $decide);
        $this->assertNull($decide[0][2]['inactive_links_counted']);
    }

    /**
     * Every saveRelation() call emits exactly one relation.decide entry whose path is one of the nine slugs
     *
     * @return void
     */
    public function testRelationDecideEmittedExactlyOncePerCall(): void
    {
        $this->relationRows = [
            $this->row(1, self::OTHER_CUSTOMER_ID, self::PRODUCT_ID, self::STORE_ID, 1),
        ];
        $this->initTag(Tag::STATUS_PENDING);

        $this->saveRelation();
        $this->saveRelation();

        $decisions = $this->logsByOperation('relation.decide');
        $this->assertCount(2, $decisions);
        foreach ($decisions as $entry) {
            $this->assertContains($entry[2]['decision_path'], self::allDecisionPaths());
        }
    }

    /**
     * A failing relation save is logged with an error_code and rethrown
     *
     * @return void
     */
    public function testSaveRelationFailureIsLoggedAndRethrown(): void
    {
        $this->initTag(Tag::STATUS_PENDING);
        $relationResource = $this->createMock(RelationResource::class);
        $relationResource->method('getIdFieldName')->willReturn('tag_relation_id');
        $relationResource->method('save')->willThrowException(
            new \Exception('SQLSTATE[23000]: Integrity constraint violation: 1452 foreign key constraint fails')
        );
        $relationLogger = $this->createMock(LoggerInterface::class);
        $factory = $this->createPartialMock(TagRelationFactory::class, ['create']);
        $factory->method('create')->willReturnCallback(
            fn () => new TagRelation(
                $this->contextMock,
                $this->registryMock,
                $relationLogger,
                $this->operationIdGeneratorMock,
                $relationResource
            )
        );
        $property = new \ReflectionProperty(Tag::class, 'tagRelationFactory');
        $property->setValue($this->tag, $factory);

        try {
            $this->saveRelation();
            $this->fail('Exception expected');
        } catch (\Exception $e) {
            $this->assertStringContainsString('foreign key', $e->getMessage());
        }

        $errors = array_values(array_filter(
            $this->logsByOperation('relation.save'),
            fn (array $entry) => $entry[0] === 'error'
        ));
        $this->assertCount(1, $errors);
        $this->assertSame('failure', $errors[0][2]['outcome']);
        $this->assertSame('relation.save.fk_violation', $errors[0][2]['error_code']);
        $this->assertSame('pending.customer_unlinked', $errors[0][2]['decision_path']);
    }

    /**
     * @return string[]
     */
    private static function allDecisionPaths(): array
    {
        return [
            'approved.product_untagged',
            'approved.customer_unlinked',
            'approved.customer_link_inactive',
            'approved.customer_link_active',
            'pending.customer_unlinked',
            'pending.customer_link_inactive',
            'pending.customer_link_active',
            'disabled.customer_already_linked',
            'disabled.reopen_to_pending',
        ];
    }

    /**
     * @param int $status
     * @return void
     */
    private function initTag(int $status): void
    {
        $this->tag->setData(['tag_id' => self::TAG_ID, 'name' => 'gift', 'status' => $status]);
    }

    /**
     * @return string
     */
    private function saveRelation(): string
    {
        return $this->tag->saveRelation(self::PRODUCT_ID, self::CUSTOMER_ID, self::STORE_ID);
    }

    /**
     * Build a fake tag_relation row for the tested tag
     *
     * @param int $id
     * @param int $customerId
     * @param int $productId
     * @param int $storeId
     * @param int $active
     * @return array
     */
    private function row(int $id, int $customerId, int $productId, int $storeId, int $active): array
    {
        return [
            'tag_relation_id' => $id,
            'tag_id' => self::TAG_ID,
            'customer_id' => $customerId,
            'product_id' => $productId,
            'store_id' => $storeId,
            'active' => $active,
            'created_at' => '2026-01-01 00:00:00',
        ];
    }

    /**
     * Fake of Relation::loadByTagCustomer() (same filters as the SQL, row data replaces model data)
     *
     * @param TagRelation $model
     * @return RelationResource
     */
    private function fakeLoadByTagCustomer(TagRelation $model)
    {
        if ($model->getTagId() && $model->getCustomerId()) {
            $found = [];
            foreach ($this->relationRows as $row) {
                if ($row['tag_id'] == $model->getTagId()
                    && $row['customer_id'] == $model->getCustomerId()
                    && (!$model->getProductId() || $row['product_id'] == $model->getProductId())
                    && (!$model->hasStoreId() || $row['store_id'] == $model->getStoreId())
                ) {
                    $found = $row;
                    break;
                }
            }
            $model->setData($found);
        }
        return $this->relationResourceMock;
    }

    /**
     * Fake of Relation::getProductIds() (active flag ignored, as in M1)
     *
     * @param TagRelation $model
     * @return array
     */
    private function fakeGetProductIds(TagRelation $model): array
    {
        $ids = [];
        foreach ($this->relationRows as $row) {
            if ($model->getTagId() === null || $row['tag_id'] != $model->getTagId()) {
                continue;
            }
            if ($model->getCustomerId() !== null && $row['customer_id'] != $model->getCustomerId()) {
                continue;
            }
            if ($model->hasStoreId() && $row['store_id'] != $model->getStoreId()) {
                continue;
            }
            $ids[] = (string)$row['product_id'];
        }
        return $ids;
    }

    /**
     * Fake of Relation::hasActiveProductRelation()
     *
     * @param TagRelation $model
     * @return bool
     */
    private function fakeHasActiveProductRelation(TagRelation $model): bool
    {
        if ($this->activeRelationCheckFails) {
            throw new \RuntimeException('Lost connection to MySQL server');
        }
        foreach ($this->relationRows as $row) {
            if ($row['tag_id'] == $model->getTagId()
                && $row['product_id'] == $model->getProductId()
                && (int)$row['active'] === TagRelation::STATUS_ACTIVE
                && (!$model->hasStoreId() || $row['store_id'] == $model->getStoreId())
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Assert the common data of a relation saved by saveRelation()
     *
     * @param array $data
     * @return void
     */
    private function assertRelationData(array $data): void
    {
        $this->assertSame(self::TAG_ID, $data['tag_id']);
        $this->assertSame(self::PRODUCT_ID, $data['product_id']);
        $this->assertSame(self::CUSTOMER_ID, $data['customer_id']);
        $this->assertSame(self::STORE_ID, $data['store_id']);
        $this->assertSame(TagRelation::STATUS_ACTIVE, $data['active']);
        $this->assertSame(self::NOW, $data['created_at']);
    }

    /**
     * @return void
     */
    private function assertRelationCreated(): void
    {
        $this->assertCount(1, $this->savedRelations, 'Exactly one relation must be saved');
        $this->assertRelationData($this->savedRelations[0]);
        $this->assertEmpty(
            $this->savedRelations[0]['tag_relation_id'] ?? null,
            'A new relation must be inserted (no relation id)'
        );
    }

    /**
     * @param int $relationId
     * @return void
     */
    private function assertRelationReactivated(int $relationId): void
    {
        $this->assertCount(1, $this->savedRelations, 'Exactly one relation must be saved');
        $this->assertRelationData($this->savedRelations[0]);
        $this->assertSame(
            $relationId,
            $this->savedRelations[0]['tag_relation_id'] ?? null,
            'The existing inactive relation must be updated'
        );
    }

    /**
     * @return void
     */
    private function assertTagNotSaved(): void
    {
        $this->assertSame([], $this->savedTags, 'The tag must not be saved');
    }

    /**
     * @return void
     */
    private function assertNothingPersisted(): void
    {
        $this->assertSame([], $this->savedRelations, 'No relation must be saved');
        $this->assertTagNotSaved();
    }

    /**
     * @return void
     */
    private function assertTagReopenedAndRelationCreated(): void
    {
        $this->assertCount(1, $this->savedTags, 'The tag must be saved once');
        $this->assertSame(Tag::STATUS_PENDING, $this->savedTags[0]['status']);
        $this->assertSame(self::TAG_ID, $this->savedTags[0]['tag_id']);
        $this->assertSame(Tag::STATUS_PENDING, $this->tag->getStatus());
        $this->assertRelationCreated();
        // M1 order: tag status flip is saved before the relation.
        $this->assertSame(['tag_save', 'relation_save'], $this->persistenceSequence);
    }

    /**
     * Exactly one relation.decide INFO entry with the expected decision fields
     *
     * @param string $path
     * @param string $resultCode
     * @param string $persistedChange
     * @param int $statusBefore
     * @param bool $inactiveLinksCounted
     * @return void
     */
    private function assertDecisionLogged(
        string $path,
        string $resultCode,
        string $persistedChange,
        int $statusBefore,
        bool $inactiveLinksCounted
    ): void {
        $entries = $this->logsByOperation('relation.decide');
        $this->assertCount(1, $entries, 'Exactly one relation.decide entry expected');
        [$level, , $context] = $entries[0];
        $this->assertSame('info', $level);
        $this->assertSame('Magento_Tag', $context['module']);
        $this->assertSame(self::OPERATION_ID, $context['operation_id']);
        $this->assertSame($path, $context['decision_path']);
        $this->assertSame($resultCode, $context['result_code']);
        $this->assertSame($persistedChange, $context['persisted_change']);
        $this->assertSame($statusBefore, $context['tag_status_before']);
        $this->assertSame($inactiveLinksCounted, $context['inactive_links_counted']);
        $this->assertSame(self::TAG_ID, $context['tag_id']);
        $this->assertSame(self::CUSTOMER_ID, $context['customer_id']);
        $this->assertSame(self::PRODUCT_ID, $context['product_id']);
        $this->assertSame(self::STORE_ID, $context['store_id']);
        $this->assertSame([], $this->logsByOperation('relation.decide_skipped'));
    }

    /**
     * @return void
     */
    private function assertReopenWarning(): void
    {
        $entries = $this->logsByOperation('tag.status.auto_reopened');
        $this->assertCount(1, $entries, 'Exactly one tag.status.auto_reopened entry expected');
        [$level, , $context] = $entries[0];
        $this->assertSame('warning', $level);
        $this->assertSame('Magento_Tag', $context['module']);
        $this->assertSame(self::OPERATION_ID, $context['operation_id']);
        $this->assertSame('tag.status.auto_reopened', $context['error_code']);
        $this->assertSame('global', $context['scope']);
        $this->assertSame(Tag::STATUS_DISABLED, $context['previous_status']);
        $this->assertSame(Tag::STATUS_PENDING, $context['new_status']);
        $this->assertSame(self::TAG_ID, $context['tag_id']);
        $this->assertSame(self::CUSTOMER_ID, $context['customer_id']);
        $this->assertSame(self::PRODUCT_ID, $context['product_id']);
        $this->assertSame(self::STORE_ID, $context['store_id']);
    }

    /**
     * @return void
     */
    private function assertNoReopenWarning(): void
    {
        $this->assertSame([], $this->logsByOperation('tag.status.auto_reopened'));
    }

    /**
     * Assert the single section-14 "Duplicate/rejected tags" WARNING (or its absence when $errorCode is null)
     *
     * @param string|null $errorCode
     * @param string|null $result
     * @return void
     */
    private function assertNoOpWarning(?string $errorCode, ?string $result = null): void
    {
        $warnings = array_values(array_filter(
            $this->logsByOperation('relation.save'),
            fn (array $entry) => $entry[0] === 'warning'
        ));
        if ($errorCode === null) {
            $this->assertSame([], $warnings);
            return;
        }
        $this->assertCount(1, $warnings);
        $this->assertSame($errorCode, $warnings[0][2]['error_code']);
        $this->assertSame($result, $warnings[0][2]['saveRelation_result']);
        $this->assertSame(self::CUSTOMER_ID, $warnings[0][2]['customer_id']);
        $this->assertArrayHasKey('tag_id', $warnings[0][2]);
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
