<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model\Logging;

/**
 * Shared tag.log context scaffolding (Project Spec section 14): module name, duration and context merge
 */
final class Context
{
    /**
     * Value of the mandatory "module" field of every Magento_Tag log entry
     */
    public const MODULE = 'Magento_Tag';

    /**
     * Elapsed milliseconds since $startTime (microtime(true))
     *
     * @param float $startTime
     * @return float
     */
    public static function durationMs(float $startTime): float
    {
        return round((microtime(true) - $startTime) * 1000, 2);
    }

    /**
     * Merge entry-specific fields into the base operation context; keys in $extra win
     *
     * @param array $base
     * @param array $extra
     * @return array
     */
    public static function merge(array $base, array $extra): array
    {
        return array_replace($base, $extra);
    }
}
