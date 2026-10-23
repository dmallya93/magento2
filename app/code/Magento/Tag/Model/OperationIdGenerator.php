<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model;

use Magento\Framework\DataObject\IdentityGeneratorInterface;

/**
 * Holds the UUID correlation id ("operation_id") shared by all Magento_Tag log entries of one operation.
 *
 * Entry points (controller actions, API calls, CLI commands) call startOperation(); every other layer
 * reads the current id via getOperationId(). The class is a shared DI instance, so all collaborators
 * within the same request observe the same id.
 */
class OperationIdGenerator
{
    /**
     * @var IdentityGeneratorInterface
     */
    private IdentityGeneratorInterface $identityGenerator;

    /**
     * @var string|null
     */
    private ?string $operationId = null;

    /**
     * @param IdentityGeneratorInterface $identityGenerator
     */
    public function __construct(IdentityGeneratorInterface $identityGenerator)
    {
        $this->identityGenerator = $identityGenerator;
    }

    /**
     * Return the current operation id, lazily generating one on first call.
     *
     * @return string
     */
    public function getOperationId(): string
    {
        if ($this->operationId === null) {
            $this->operationId = $this->generate();
        }

        return $this->operationId;
    }

    /**
     * Start a new operation: generate, store and return a fresh operation id.
     *
     * @return string
     */
    public function startOperation(): string
    {
        $this->operationId = $this->generate();

        return $this->operationId;
    }

    /**
     * Adopt an externally provided operation id.
     *
     * @param string $id
     * @return void
     */
    public function setOperationId(string $id): void
    {
        $this->operationId = $id;
    }

    /**
     * Generate a new UUID.
     *
     * @return string
     */
    private function generate(): string
    {
        return (string)$this->identityGenerator->generateId();
    }
}
