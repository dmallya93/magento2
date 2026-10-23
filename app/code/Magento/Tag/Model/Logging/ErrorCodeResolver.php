<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model\Logging;

use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\Exception\AlreadyExistsException;

/**
 * Maps a persistence exception to an error_code slug of the Project Spec section 14 fingerprint table
 */
final class ErrorCodeResolver
{
    /**
     * Resolve "<prefix>.<duplicateSlug>", "<prefix>.fk_violation" or "<prefix>.failed" for the exception chain
     *
     * @param \Throwable $exception
     * @param string $prefix e.g. "relation.save" or "tag.save"
     * @param string $duplicateSlug e.g. "duplicate" or "unique_violation"
     * @return string
     */
    public static function resolve(\Throwable $exception, string $prefix, string $duplicateSlug = 'duplicate'): string
    {
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof DuplicateException || $e instanceof AlreadyExistsException) {
                return $prefix . '.' . $duplicateSlug;
            }
            $message = (string)$e->getMessage();
            if (stripos($message, 'foreign key constraint') !== false || preg_match('/\b145[12]\b/', $message)) {
                return $prefix . '.fk_violation';
            }
            if (stripos($message, 'Duplicate entry') !== false || preg_match('/\b1062\b/', $message)) {
                return $prefix . '.' . $duplicateSlug;
            }
        }
        return $prefix . '.failed';
    }
}
