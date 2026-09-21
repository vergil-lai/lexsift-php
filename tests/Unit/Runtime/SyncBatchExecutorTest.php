<?php

declare(strict_types=1);

use VergilLai\SensitiveText\Dictionary\SensitiveDictionary;
use VergilLai\SensitiveText\Matcher\AhoCorasickMatcher;
use VergilLai\SensitiveText\Normalizer\TextNormalizer;
use VergilLai\SensitiveText\Runtime\SyncBatchExecutor;
use VergilLai\SensitiveText\SensitiveText;
use VergilLai\SensitiveText\Tests\Helpers\FakeRepository;

it('preserves batch keys and scans lazily', function () {
    $repository = new FakeRepository(new SensitiveDictionary('1', []));
    $scanner = new SensitiveText(new TextNormalizer(), $repository, [new AhoCorasickMatcher()]);

    $results = (new SyncBatchExecutor($scanner))->scan(['a' => '正常', 7 => '']);

    expect($repository->loadCalls)->toBe(0)
        ->and($results)->toBeInstanceOf(Generator::class);

    $materialized = iterator_to_array($results);

    expect(array_keys($materialized))->toBe(['a', 7])
        ->and($materialized['a']->original)->toBe('正常')
        ->and($materialized[7]->original)->toBe('')
        ->and($repository->loadCalls)->toBe(1);
});

it('does not consume a generator before batch iteration begins', function () {
    $consumed = 0;
    $texts = static function () use (&$consumed): Generator {
        ++$consumed;
        yield 'first' => '正常';
    };
    $repository = new FakeRepository(new SensitiveDictionary('1', []));
    $scanner = new SensitiveText(new TextNormalizer(), $repository, [new AhoCorasickMatcher()]);

    $results = (new SyncBatchExecutor($scanner))->scan($texts());

    expect($consumed)->toBe(0);
    iterator_to_array($results);
    expect($consumed)->toBe(1);
});
