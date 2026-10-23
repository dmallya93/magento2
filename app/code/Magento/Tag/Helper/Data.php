<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Phrase;
use Magento\Tag\Model\Logging\Context as LogContext;
use Magento\Tag\Model\OperationIdGenerator;
use Magento\Tag\Model\Tag;
use Psr\Log\LoggerInterface;

/**
 * Tag data helper: status labels/options and customer tag-input parsing.
 *
 * Port of Mage_Tag_Helper_Data.
 *
 * @api
 */
class Data extends AbstractHelper
{
    /**
     * Regex used by M1 to detect single-quoted phrases and whitespace separators (kept byte-for-byte).
     */
    private const TAG_SPLIT_PATTERN = "/(\'(.*?)\')|(\s+)/i";

    /**
     * Operation slug for tag parsing log entries
     */
    private const LOG_OPERATION_PARSE = 'tag.parse';

    /**
     * @var string
     */
    protected $_moduleName = 'Magento_Tag';

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var OperationIdGenerator
     */
    private OperationIdGenerator $operationIdGenerator;

    /**
     * @param Context $context
     * @param LoggerInterface $logger
     * @param OperationIdGenerator $operationIdGenerator
     */
    public function __construct(
        Context $context,
        LoggerInterface $logger,
        OperationIdGenerator $operationIdGenerator
    ) {
        parent::__construct($context);
        $this->logger = $logger;
        $this->operationIdGenerator = $operationIdGenerator;
    }

    /**
     * Retrieve tag statuses as status => label map
     *
     * @return array<int, Phrase>
     */
    public function getStatusesArray(): array
    {
        return [
            Tag::STATUS_DISABLED => __('Disabled'),
            Tag::STATUS_PENDING => __('Pending'),
            Tag::STATUS_APPROVED => __('Approved'),
        ];
    }

    /**
     * Retrieve tag statuses as option array (label/value pairs)
     *
     * @return array<int, array{label: Phrase, value: int}>
     */
    public function getStatusesOptionsArray(): array
    {
        return [
            [
                'label' => __('Disabled'),
                'value' => Tag::STATUS_DISABLED,
            ],
            [
                'label' => __('Pending'),
                'value' => Tag::STATUS_PENDING,
            ],
            [
                'label' => __('Approved'),
                'value' => Tag::STATUS_APPROVED,
            ],
        ];
    }

    /**
     * Check tags on the correctness of symbols and split string to array of tags
     *
     * Behaves exactly like M1: every single-quoted phrase and every whitespace run becomes a "\n"
     * separator (the quoted phrase itself is kept, quotes included), then the string is exploded.
     * The result is the RAW token list (it may contain empty strings and surrounding quotes);
     * callers must pass it through cleanTags(), as M1 callers do: cleanTags(extractTags($input)).
     *
     * @param string|null $tagNamesInString
     * @return string[]
     */
    public function extractTags(?string $tagNamesInString): array
    {
        $rawInput = (string)$tagNamesInString;
        $tokens = explode("\n", (string)preg_replace(self::TAG_SPLIT_PATTERN, "$1\n", $rawInput));

        $parsedTags = array_values($this->cleanTags($tokens));
        $this->logger->debug('Tag input parsed', [
            'module' => LogContext::MODULE,
            'operation' => self::LOG_OPERATION_PARSE,
            'operation_id' => $this->operationIdGenerator->getOperationId(),
            'raw_input' => $rawInput,
            'parsed_tags' => $parsedTags,
            'tag_count' => count($parsedTags),
            'raw_token_count' => count($tokens),
        ]);

        return $tokens;
    }

    /**
     * Clear tag from the separating characters
     *
     * Same as M1 (strip surrounding single quotes, then surrounding whitespace, drop empty entries,
     * keep original keys, no de-duplication) plus Unicode NFC normalization of each remaining tag.
     *
     * @param array $tagNamesArr
     * @return array
     */
    public function cleanTags(array $tagNamesArr): array
    {
        foreach (array_keys($tagNamesArr) as $key) {
            $tagName = trim((string)$tagNamesArr[$key], "'");
            $tagName = trim($tagName);
            if ($tagName === '') {
                unset($tagNamesArr[$key]);
                continue;
            }
            $tagNamesArr[$key] = $this->normalizeUnicode($tagName);
        }

        return $tagNamesArr;
    }

    /**
     * Normalize a tag name to Unicode NFC, leaving it untouched if normalization is impossible
     *
     * @param string $tagName
     * @return string
     */
    private function normalizeUnicode(string $tagName): string
    {
        if (!class_exists(\Normalizer::class)) {
            return $tagName;
        }
        $normalized = \Normalizer::normalize($tagName, \Normalizer::FORM_C);

        return is_string($normalized) ? $normalized : $tagName;
    }
}
