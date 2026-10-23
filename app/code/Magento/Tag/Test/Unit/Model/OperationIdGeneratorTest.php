<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model;

use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Tag\Model\OperationIdGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(OperationIdGenerator::class)]
class OperationIdGeneratorTest extends TestCase
{
    /**
     * @var IdentityGeneratorInterface|MockObject
     */
    private IdentityGeneratorInterface $identityGenerator;

    /**
     * @var OperationIdGenerator
     */
    private OperationIdGenerator $model;

    protected function setUp(): void
    {
        $this->identityGenerator = $this->createMock(IdentityGeneratorInterface::class);
        $this->model = new OperationIdGenerator($this->identityGenerator);
    }

    /**
     * The id is generated lazily on the first call and reused afterwards.
     *
     * @return void
     */
    public function testGetOperationIdGeneratesOnceAndIsStable(): void
    {
        $uuid = '0b6c2f3e-8d4a-4c1e-9f5a-2a7d3b9e1c40';
        $this->identityGenerator->expects($this->once())
            ->method('generateId')
            ->willReturn($uuid);

        $this->assertSame($uuid, $this->model->getOperationId());
        $this->assertSame($uuid, $this->model->getOperationId());
    }

    /**
     * startOperation() always generates and stores a new id.
     *
     * @return void
     */
    public function testStartOperationGeneratesNewId(): void
    {
        $first = '11111111-1111-4111-8111-111111111111';
        $second = '22222222-2222-4222-8222-222222222222';
        $this->identityGenerator->expects($this->exactly(2))
            ->method('generateId')
            ->willReturnOnConsecutiveCalls($first, $second);

        $this->assertSame($first, $this->model->getOperationId());
        $this->assertSame($second, $this->model->startOperation());
        $this->assertSame($second, $this->model->getOperationId());
    }

    /**
     * An externally provided id is adopted without generating a new one.
     *
     * @return void
     */
    public function testSetOperationIdIsAdopted(): void
    {
        $this->identityGenerator->expects($this->never())->method('generateId');

        $this->model->setOperationId('external-id');

        $this->assertSame('external-id', $this->model->getOperationId());
    }

    /**
     * startOperation() replaces a previously adopted external id.
     *
     * @return void
     */
    public function testStartOperationReplacesExternalId(): void
    {
        $uuid = '33333333-3333-4333-8333-333333333333';
        $this->identityGenerator->expects($this->once())
            ->method('generateId')
            ->willReturn($uuid);

        $this->model->setOperationId('external-id');

        $this->assertSame($uuid, $this->model->startOperation());
        $this->assertSame($uuid, $this->model->getOperationId());
    }
}
