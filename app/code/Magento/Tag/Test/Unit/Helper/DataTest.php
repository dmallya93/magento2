<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Tag\Helper\Data;
use Magento\Tag\Model\OperationIdGenerator;
use Magento\Tag\Model\Tag;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for \Magento\Tag\Helper\Data (port of Mage_Tag_Helper_Data)
 */
#[AllowMockObjectsWithoutExpectations]
class DataTest extends TestCase
{
    private const OPERATION_ID = '6f1c2a3e-1b2c-4d5e-8f90-0123456789ab';

    /**
     * @var LoggerInterface|MockObject
     */
    private $loggerMock;

    /**
     * @var OperationIdGenerator|MockObject
     */
    private $operationIdGeneratorMock;

    /**
     * @var Data
     */
    private Data $helper;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $contextMock = $this->createMock(Context::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->operationIdGeneratorMock = $this->createMock(OperationIdGenerator::class);
        $this->operationIdGeneratorMock->method('getOperationId')->willReturn(self::OPERATION_ID);

        $this->helper = new Data($contextMock, $this->loggerMock, $this->operationIdGeneratorMock);
    }

    /**
     * Raw extractTags() output must match M1 exactly (preg_replace "$1\n" + explode("\n")).
     *
     * @param string $input
     * @param array $expectedRaw
     * @param array $expectedClean
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    #[DataProvider('parsingDataProvider')]
    public function testExtractTagsReturnsRawM1Tokens(string $input, array $expectedRaw, array $expectedClean): void
    {
        $this->assertSame($expectedRaw, $this->helper->extractTags($input));
    }

    /**
     * cleanTags(extractTags()) — the M1 call chain used by controller/API — yields the final tag list.
     *
     * @param string $input
     * @param array $expectedRaw
     * @param array $expectedClean
     * @return void
     */
    #[DataProvider('parsingDataProvider')]
    public function testExtractAndCleanTags(string $input, array $expectedRaw, array $expectedClean): void
    {
        $this->assertSame($expectedClean, $this->helper->cleanTags($this->helper->extractTags($input)));
    }

    /**
     * Expected values verified against the original M1 code. Keys of the cleaned array are preserved
     * (M1 uses unset()), so gaps are expected where empty tokens were dropped.
     *
     * @return array
     */
    public static function parsingDataProvider(): array
    {
        return [
            'single word' => ['foo', ['foo'], ['foo']],
            'single space' => ['foo bar', ['foo', 'bar'], ['foo', 'bar']],
            'multiple spaces' => ['foo    bar', ['foo', 'bar'], ['foo', 'bar']],
            'tab and newline' => ["foo\tbar\nbaz", ['foo', 'bar', 'baz'], ['foo', 'bar', 'baz']],
            'crlf' => ["foo\r\nbar", ['foo', 'bar'], ['foo', 'bar']],
            'leading and trailing whitespace' => ['  foo  ', ['', 'foo', ''], [1 => 'foo']],
            'single-quoted multi-word' => ["'multi word tag'", ["'multi word tag'", ''], ['multi word tag']],
            'mixed input' => [
                "alpha 'two words' beta",
                ['alpha', "'two words'", '', 'beta'],
                [0 => 'alpha', 1 => 'two words', 3 => 'beta'],
            ],
            'adjacent quoted phrases' => ["'a' 'b'", ["'a'", '', "'b'", ''], [0 => 'a', 2 => 'b']],
            'empty string' => ['', [''], []],
            'only whitespace' => ["   \t\n ", ['', ''], []],
            'unterminated quote' => ["'unterminated tag", ["'unterminated", 'tag'], ['unterminated', 'tag']],
            'empty quotes' => ["''", ["''", ''], []],
            'quote glued to word' => ["ab'c d'e", ["ab'c d'", 'e'], ["ab'c d", 'e']],
            'trailing quoted blank' => ["x' '", ["x' '", ''], ["x'"]],
            'duplicates are not de-duplicated' => ['foo foo', ['foo', 'foo'], ['foo', 'foo']],
            'zero is a valid tag' => ['0', ['0'], ['0']],
            'multibyte and emoji' => [
                "café 🎉 ' 東京 タワー '",
                ['café', '🎉', "' 東京 タワー '", ''],
                ['café', '🎉', '東京 タワー'],
            ],
        ];
    }

    /**
     * @return void
     */
    public function testExtractTagsNormalizesDecomposedUnicodeAfterCleaning(): void
    {
        $decomposed = "cafe\u{0301}";
        $composed = "caf\u{00E9}";

        $raw = $this->helper->extractTags($decomposed . ' tag');
        $this->assertSame([$decomposed, 'tag'], $raw, 'extractTags() must not alter token bytes');
        $this->assertSame([$composed, 'tag'], $this->helper->cleanTags($raw));
    }

    /**
     * @return void
     */
    public function testExtractTagsAcceptsNull(): void
    {
        $this->assertSame([''], $this->helper->extractTags(null));
    }

    /**
     * @param array $input
     * @param array $expected
     * @return void
     */
    #[DataProvider('cleanTagsDataProvider')]
    public function testCleanTags(array $input, array $expected): void
    {
        $this->assertSame($expected, $this->helper->cleanTags($input));
    }

    /**
     * @return array
     */
    public static function cleanTagsDataProvider(): array
    {
        return [
            'empty array' => [[], []],
            'trims whitespace' => [["  foo\t", "\nbar "], ['foo', 'bar']],
            'strips surrounding quotes' => [["'foo'", "'bar baz'"], ['foo', 'bar baz']],
            'quotes stripped before whitespace' => [["' foo '"], ['foo']],
            'drops empty and blank entries keeping keys' => [
                ['a' => 'x', 'b' => '', 'c' => '   ', 'd' => "''", 'e' => 'y'],
                ['a' => 'x', 'e' => 'y'],
            ],
            'keeps zero' => [['0', ' 0 '], ['0', '0']],
            'no de-duplication' => [['foo', 'foo', ' foo'], ['foo', 'foo', 'foo']],
            'nfc normalization' => [["cafe\u{0301}", "  e\u{0301}l\u{00E8}ve "], ["caf\u{00E9}", "\u{00E9}l\u{00E8}ve"]],
            'already nfc unchanged' => [['東京', '🎉'], ['東京', '🎉']],
            'invalid utf-8 left untouched' => [["\xff"], ["\xff"]],
        ];
    }

    /**
     * @return void
     */
    public function testExtractTagsEmitsDebugLog(): void
    {
        $input = "alpha 'two words' alpha";
        $this->loggerMock->expects($this->once())
            ->method('debug')
            ->with(
                $this->isString(),
                $this->callback(function (array $context): bool {
                    $this->assertSame('Magento_Tag', $context['module']);
                    $this->assertSame('tag.parse', $context['operation']);
                    $this->assertSame(self::OPERATION_ID, $context['operation_id']);
                    $this->assertSame("alpha 'two words' alpha", $context['raw_input']);
                    $this->assertSame(['alpha', 'two words', 'alpha'], $context['parsed_tags']);
                    $this->assertSame(3, $context['tag_count']);
                    $this->assertSame(4, $context['raw_token_count']);
                    return true;
                })
            );
        $this->loggerMock->expects($this->never())->method('info');

        $this->helper->extractTags($input);
    }

    /**
     * @return void
     */
    public function testExtractTagsEmitsDebugLogForEmptyInput(): void
    {
        $this->loggerMock->expects($this->once())
            ->method('debug')
            ->with(
                $this->isString(),
                $this->callback(function (array $context): bool {
                    $this->assertSame('tag.parse', $context['operation']);
                    $this->assertSame('', $context['raw_input']);
                    $this->assertSame([], $context['parsed_tags']);
                    $this->assertSame(0, $context['tag_count']);
                    return true;
                })
            );

        $this->helper->extractTags('');
    }

    /**
     * @return void
     */
    public function testCleanTagsDoesNotLog(): void
    {
        $this->loggerMock->expects($this->never())->method($this->anything());

        $this->helper->cleanTags(['foo']);
    }

    /**
     * @return void
     */
    public function testGetStatusesArray(): void
    {
        $statuses = $this->helper->getStatusesArray();

        $this->assertSame([Tag::STATUS_DISABLED, Tag::STATUS_PENDING, Tag::STATUS_APPROVED], array_keys($statuses));
        $this->assertSame([-1, 0, 1], array_keys($statuses));
        $this->assertSame(
            [-1 => 'Disabled', 0 => 'Pending', 1 => 'Approved'],
            array_map('strval', $statuses)
        );
    }

    /**
     * @return void
     */
    public function testGetStatusesOptionsArray(): void
    {
        $options = $this->helper->getStatusesOptionsArray();

        $normalized = array_map(
            static fn (array $option): array => ['label' => (string)$option['label'], 'value' => $option['value']],
            $options
        );
        $this->assertSame(
            [
                ['label' => 'Disabled', 'value' => Tag::STATUS_DISABLED],
                ['label' => 'Pending', 'value' => Tag::STATUS_PENDING],
                ['label' => 'Approved', 'value' => Tag::STATUS_APPROVED],
            ],
            $normalized
        );
        $this->assertSame([-1, 0, 1], array_column($options, 'value'));
    }
}
