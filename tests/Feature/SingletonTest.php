<?php

declare(strict_types=1);

use VergilLai\SensitiveText\Dictionary\SensitiveDictionary;
use VergilLai\SensitiveText\Dictionary\SensitiveTerm;
use VergilLai\SensitiveText\Matcher\AhoCorasickMatcher;
use VergilLai\SensitiveText\Normalizer\TextNormalizer;
use VergilLai\SensitiveText\SensitiveText;
use VergilLai\SensitiveText\SensitiveTextConfig;
use VergilLai\SensitiveText\Tests\Helpers\FakeRepository;

it('keeps the default singleton separate from explicit instances', function () {
    expect(SensitiveText::instance())->toBe(SensitiveText::instance());

    $repository = new FakeRepository(new SensitiveDictionary('custom', [new SensitiveTerm('自定义')]));
    $custom = new SensitiveText(new TextNormalizer(), $repository, [new AhoCorasickMatcher()]);

    expect($custom)->not->toBe(SensitiveText::instance())
        ->and($custom->scan('自定义')->matched())->toBeTrue()
        ->and(SensitiveText::instance()->stats()->dictionaryVersion)->toBeNull();
});

it('creates independent configured scanners without connecting to redis', function () {
    $config = new SensitiveTextConfig(redisUrl: 'tcp://127.0.0.1:1', redisTimeout: 0.01);

    $first = SensitiveText::fromConfig($config);
    $second = SensitiveText::fromConfig($config);

    expect($first)->not->toBe($second)
        ->and($first)->not->toBe(SensitiveText::instance())
        ->and($first->stats()->dictionaryVersion)->toBeNull()
        ->and($second->stats()->dictionaryVersion)->toBeNull();
});
