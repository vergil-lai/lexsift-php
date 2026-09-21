<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;
use VergilLai\SensitiveText\Dictionary\DictionaryCompiler;
use VergilLai\SensitiveText\Dictionary\SensitiveDictionary;
use VergilLai\SensitiveText\Dictionary\SensitiveTerm;
use VergilLai\SensitiveText\Exception\InvalidRuleException;
use VergilLai\SensitiveText\Matcher\AhoCorasickMatcher;
use VergilLai\SensitiveText\Matcher\RegexMatcher;
use VergilLai\SensitiveText\Normalizer\TextNormalizer;
use VergilLai\SensitiveText\Result\MatchResult;
use VergilLai\SensitiveText\Rules\Action;
use VergilLai\SensitiveText\Rules\RegexRule;
use VergilLai\SensitiveText\Rules\RegexTarget;
use VergilLai\SensitiveText\Rules\Severity;

final class RegexMatcherTest extends TestCase
{
    public function testItMapsNormalizedRegexOffsetsToOriginalText(): void
    {
        $normalizer = new TextNormalizer();
        $text = $normalizer->normalize('微❤️信 微信');
        $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', []));
        $matcher = new RegexMatcher([
            new RegexRule(
                'wx',
                '/微信/u',
                'contact',
                Severity::High,
                Action::Block,
                RegexTarget::Normalized,
                ['source' => 'regex'],
            ),
        ]);

        $matches = $matcher->match($text, $dictionary);

        self::assertSame([
            ['wx', '微信', '微❤️信', 'contact', Severity::High, Action::Block, 0, 4, 'regex', ['source' => 'regex']],
            ['wx', '微信', '微信', 'contact', Severity::High, Action::Block, 5, 7, 'regex', ['source' => 'regex']],
        ], array_map(
            static fn(MatchResult $match): array => [
                $match->term,
                $match->normalizedTerm,
                $match->matchedText,
                $match->category,
                $match->severity,
                $match->action,
                $match->start,
                $match->end,
                $match->matcher,
                $match->metadata,
            ],
            $matches,
        ));
    }

    public function testItMapsOriginalRegexByteOffsetsToCodepointRanges(): void
    {
        $normalizer = new TextNormalizer();
        $text = $normalizer->normalize('A中中Ｂ');
        $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', []));

        $matches = (new RegexMatcher([
            new RegexRule('chinese', '/中+/u'),
            new RegexRule('fullwidth', '/Ｂ/u'),
        ]))->match($text, $dictionary);

        self::assertSame([
            ['中中', '中中', 1, 3],
            ['b', 'Ｂ', 3, 4],
        ], array_map(
            static fn(MatchResult $match): array => [
                $match->normalizedTerm,
                $match->matchedText,
                $match->start,
                $match->end,
            ],
            $matches,
        ));
    }

    #[WithoutErrorHandler]
    public function testItRejectsInvalidPcreSyntax(): void
    {
        $this->expectException(InvalidRuleException::class);

        new RegexRule('broken', '/[/u');
    }

    #[WithoutErrorHandler]
    public function testItRejectsUnsupportedPcreModifiers(): void
    {
        $this->expectException(InvalidRuleException::class);

        new RegexRule('broken', '/微信/uz');
    }

    public function testItRejectsUnsupportedRegexDelimiters(): void
    {
        $this->expectException(InvalidRuleException::class);

        new RegexRule('broken', '%微信%u');
    }

    public function testItRequiresTheUnicodeModifier(): void
    {
        $this->expectException(InvalidRuleException::class);

        new RegexRule('broken', '/微信/');
    }

    public function testItIgnoresZeroWidthResultsAndReturnsNoResultForAMiss(): void
    {
        $normalizer = new TextNormalizer();
        $text = $normalizer->normalize('中文');
        $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', []));

        self::assertSame([], (new RegexMatcher([
            new RegexRule('zero', '/(?=中)/u'),
            new RegexRule('miss', '/英文/u'),
        ]))->match($text, $dictionary));
    }

    public function testItRaisesAnExplicitExceptionForRuntimePcreErrors(): void
    {
        $normalizer = new TextNormalizer();
        $text = $normalizer->normalize(str_repeat('a', 100) . '!');
        $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', []));

        $this->expectException(InvalidRuleException::class);
        $this->expectExceptionMessage('catastrophic');

        (new RegexMatcher([
            new RegexRule('catastrophic', '/(*NO_JIT)(*LIMIT_MATCH=10)(a+)+$/u'),
        ]))->match($text, $dictionary);
    }

    public function testItKeepsPregMatchAllNonOverlapSemanticsWithinOneRule(): void
    {
        $normalizer = new TextNormalizer();
        $text = $normalizer->normalize('ababa');
        $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', []));

        $matches = (new RegexMatcher([
            new RegexRule('aba', '/aba/u'),
        ]))->match($text, $dictionary);

        self::assertSame([[0, 3]], array_map(
            static fn(MatchResult $match): array => [$match->start, $match->end],
            $matches,
        ));
    }

    public function testItPreservesOverlappingMatchesFromSeparateRegexRules(): void
    {
        $normalizer = new TextNormalizer();
        $text = $normalizer->normalize('ababa');
        $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', []));

        $matches = (new RegexMatcher([
            new RegexRule('aba', '~aba~u'),
            new RegexRule('bab', '#bab#u'),
        ]))->match($text, $dictionary);

        self::assertSame([
            ['aba', 0, 3],
            ['bab', 1, 4],
        ], array_map(
            static fn(MatchResult $match): array => [$match->term, $match->start, $match->end],
            $matches,
        ));
    }

    public function testItPreservesRegexAndAhoCorasickMatchesForTheSameRange(): void
    {
        $normalizer = new TextNormalizer();
        $text = $normalizer->normalize('微信');
        $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', [
            new SensitiveTerm('微信'),
        ]));

        $matches = array_merge(
            (new AhoCorasickMatcher())->match($text, $dictionary),
            (new RegexMatcher([new RegexRule('wx', '/微信/u')]))->match($text, $dictionary),
        );

        self::assertSame([
            ['aho_corasick', 0, 2],
            ['regex', 0, 2],
        ], array_map(
            static fn(MatchResult $match): array => [$match->matcher, $match->start, $match->end],
            $matches,
        ));
    }
}
