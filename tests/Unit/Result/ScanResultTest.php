<?php

declare(strict_types=1);

use VergilLai\SensitiveText\Result\MatchResult;
use VergilLai\SensitiveText\Result\ScanResult;
use VergilLai\SensitiveText\Rules\Action;
use VergilLai\SensitiveText\Rules\Severity;

it('returns an allow recommendation for empty results', function () {
    $result = new ScanResult('正常', []);

    expect($result->count())->toBe(0)
        ->and($result->matched())->toBeFalse()
        ->and($result->matches())->toBe([])
        ->and($result->highestSeverity())->toBeNull()
        ->and($result->recommendedAction())->toBe(Action::Allow)
        ->and($result->shouldBlock())->toBeFalse()
        ->and($result->shouldReview())->toBeFalse()
        ->and($result->mask())->toBe('正常');
});

it('aggregates severity and action independently', function () {
    $result = new ScanResult('高低', [
        new MatchResult('高', '高', '高', 'severity', Severity::Critical, Action::Flag, 0, 1, 'aho_corasick'),
        new MatchResult('低', '低', '低', 'action', Severity::Low, Action::Block, 1, 2, 'aho_corasick'),
    ]);

    expect($result->highestSeverity())->toBe(Severity::Critical)
        ->and($result->recommendedAction())->toBe(Action::Block)
        ->and($result->shouldBlock())->toBeTrue()
        ->and($result->shouldReview())->toBeFalse();

    $review = new ScanResult('审', [
        new MatchResult('审', '审', '审', 'review', Severity::Medium, Action::Review, 0, 1, 'aho_corasick'),
    ]);

    expect($review->shouldBlock())->toBeFalse()
        ->and($review->shouldReview())->toBeTrue();
});

it('sorts matches and removes only completely identical results', function () {
    $base = new MatchResult(
        'bc',
        'bc',
        'bc',
        'base',
        Severity::Medium,
        Action::Flag,
        1,
        3,
        'aho_corasick',
        ['source' => 'test'],
    );
    $result = new ScanResult('abcd', [
        $base,
        new MatchResult('bc', 'bc', 'bc', 'base', Severity::Medium, Action::Flag, 1, 3, 'regex', ['source' => 'test']),
        new MatchResult('a', 'a', 'a', 'first', Severity::Low, Action::Allow, 0, 1, 'aho_corasick'),
        new MatchResult('bc', 'bc', 'bc', 'base', Severity::Medium, Action::Review, 1, 3, 'aho_corasick', ['source' => 'test']),
        new MatchResult('bc', 'bc', 'bc', 'base', Severity::Medium, Action::Flag, 1, 3, 'aho_corasick', ['source' => 'test']),
    ]);

    expect(array_map(
        static fn(MatchResult $match): array => [$match->start, $match->end, $match->matcher, $match->term, $match->action],
        $result->matches(),
    ))->toBe([
        [0, 1, 'aho_corasick', 'a', Action::Allow],
        [1, 3, 'aho_corasick', 'bc', Action::Flag],
        [1, 3, 'aho_corasick', 'bc', Action::Review],
        [1, 3, 'regex', 'bc', Action::Flag],
    ]);
});

it('rejects matches outside the original range or with a mismatched slice', function () {
    $invalidMatches = [
        new MatchResult('a', 'a', 'a', 'test', Severity::Low, Action::Flag, -1, 1, 'aho_corasick'),
        new MatchResult('a', 'a', 'a', 'test', Severity::Low, Action::Flag, 1, 1, 'aho_corasick'),
        new MatchResult('a', 'a', 'a', 'test', Severity::Low, Action::Flag, 0, 4, 'aho_corasick'),
        new MatchResult('emoji', 'emoji', '文', 'test', Severity::Low, Action::Flag, 1, 2, 'aho_corasick'),
    ];

    foreach ($invalidMatches as $match) {
        expect(fn() => new ScanResult('a😀文', [$match]))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('merges overlapping nested and adjacent ranges before masking', function () {
    $result = new ScanResult('abcdefghi', [
        new MatchResult('bcd', 'bcd', 'bcd', 'test', Severity::Low, Action::Flag, 1, 4, 'aho_corasick'),
        new MatchResult('c', 'c', 'c', 'test', Severity::Low, Action::Flag, 2, 3, 'aho_corasick'),
        new MatchResult('ef', 'ef', 'ef', 'test', Severity::Low, Action::Flag, 4, 6, 'aho_corasick'),
        new MatchResult('hi', 'hi', 'hi', 'test', Severity::Low, Action::Flag, 7, 9, 'aho_corasick'),
    ]);

    expect($result->mask('#'))->toBe('a#####g##')
        ->and($result->mask(''))->toBe('ag')
        ->and($result->mask('😀'))->toBe('a😀😀😀😀😀g😀😀');
});

it('uses the configured default mask unless a call overrides it', function () {
    $result = new ScanResult('敏感词', [
        new MatchResult('敏感', '敏感', '敏感', 'test', Severity::Low, Action::Flag, 0, 2, 'aho_corasick'),
    ], '#');

    expect($result->mask())->toBe('##词')
        ->and($result->mask('!'))->toBe('!!词');
});

it('rejects invalid or multi-codepoint masks', function (string $mask) {
    $result = new ScanResult('a', [
        new MatchResult('a', 'a', 'a', 'test', Severity::Low, Action::Flag, 0, 1, 'aho_corasick'),
    ]);

    expect(fn() => $result->mask($mask))->toThrow(InvalidArgumentException::class);
})->with([
    'ascii pair' => 'ab',
    'combining sequence' => "e\u{0301}",
    'invalid utf8' => "\xFF",
]);
