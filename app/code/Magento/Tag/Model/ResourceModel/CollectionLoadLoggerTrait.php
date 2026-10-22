<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model\ResourceModel;

use Magento\Framework\DB\Select;
use Magento\Tag\Model\Logging\Context as LogContext;

/**
 * Shared collection.load DEBUG/ERROR logging (Project Spec section 14) for the Tag collections
 *
 * Requires the using class to provide $this->operationIdGenerator, $this->_logger, getSelect() and $_items.
 */
trait CollectionLoadLoggerTrait
{
    /**
     * Run the actual load and log its result (success or failure, failures are rethrown)
     *
     * @param callable $load
     * @return $this
     * @throws \Throwable
     */
    private function runLoggedLoad(callable $load)
    {
        $startedAt = microtime(true);
        try {
            $load();
        } catch (\Throwable $e) {
            $this->logCollectionLoad($startedAt, $e);
            throw $e;
        }
        $this->logCollectionLoad($startedAt);

        return $this;
    }

    /**
     * Log a collection.load entry (DEBUG on success, ERROR on failure)
     *
     * @param float $startedAt
     * @param \Throwable|null $exception
     * @return void
     */
    private function logCollectionLoad(float $startedAt, ?\Throwable $exception = null): void
    {
        $context = [
            'module' => LogContext::MODULE,
            'operation' => 'collection.load',
            'operation_id' => $this->operationIdGenerator->getOperationId(),
            'collection_class' => static::class,
            'filter_count' => count((array)$this->getSelect()->getPart(Select::WHERE))
                + count((array)$this->getSelect()->getPart(Select::HAVING)),
            'duration_ms' => LogContext::durationMs($startedAt),
        ];

        if ($exception !== null) {
            $context['outcome'] = 'failure';
            $context['error_code'] = 'collection.load.query_error';
            $context['exception'] = $exception->getMessage();
            $this->_logger->error('Tag collection load failed', $context);
            return;
        }

        $context['outcome'] = 'success';
        $context['result_count'] = count($this->_items);
        $this->_logger->debug('Tag collection loaded', $context);
    }
}
