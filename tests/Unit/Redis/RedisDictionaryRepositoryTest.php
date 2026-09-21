<?php

declare(strict_types=1);

use VergilLai\SensitiveText\Dictionary\DictionaryJsonCodec;
use VergilLai\SensitiveText\Dictionary\RedisDictionaryRepository;
use VergilLai\SensitiveText\Dictionary\SensitiveTerm;
use VergilLai\SensitiveText\Exception\DictionaryException;
use VergilLai\SensitiveText\Rules\Action;
use VergilLai\SensitiveText\Rules\Severity;
use VergilLai\SensitiveText\Tests\Helpers\FakeRedisClient;

it('publishes and loads a versioned snapshot and rejects lost updates', function () {
    $redis = new FakeRedisClient();
    $repository = new RedisDictionaryRepository($redis);

    expect($repository->publish([new SensitiveTerm('赌博')], null))->toBe('1');

    $snapshot = $repository->load();
    expect($snapshot->version)->toBe('1')
        ->and($snapshot->terms[0]->term)->toBe('赌博');

    expect($repository->publish([], '1'))->toBe('2')
        ->and(fn() => $repository->publish([new SensitiveTerm('旧词')], '1'))
        ->toThrow(DictionaryException::class)
        ->and($repository->load()->terms)->toBe([]);
});

it('round trips every term field without storing normalized text', function () {
    $codec = new DictionaryJsonCodec();
    $term = new SensitiveTerm(
        '赌博',
        'gambling',
        Severity::Critical,
        Action::Block,
        false,
        ['source' => 'manual', 'confidence' => 0.9, 'options' => ['exact' => true, 'note' => null]],
    );

    $payload = $codec->encode([$term]);
    $decodedPayload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    $decodedTerms = $codec->decode($payload);

    expect($decodedPayload)->toBe([
        'schema' => 1,
        'terms' => [[
            'term' => '赌博',
            'category' => 'gambling',
            'severity' => 4,
            'action' => 'block',
            'enabled' => false,
            'metadata' => ['source' => 'manual', 'confidence' => 0.9, 'options' => ['exact' => true, 'note' => null]],
        ]],
    ])->and($decodedTerms)->toHaveCount(1)
        ->and($decodedTerms[0]->term)->toBe('赌博')
        ->and($decodedTerms[0]->category)->toBe('gambling')
        ->and($decodedTerms[0]->severity)->toBe(Severity::Critical)
        ->and($decodedTerms[0]->action)->toBe(Action::Block)
        ->and($decodedTerms[0]->enabled)->toBeFalse()
        ->and($decodedTerms[0]->metadata)->toBe($term->metadata);
});

it('rejects malformed dictionary payloads', function (string $payload) {
    expect(fn() => (new DictionaryJsonCodec())->decode($payload))
        ->toThrow(DictionaryException::class);
})->with([
    'invalid json' => '{',
    'root list' => '[]',
    'wrong schema' => '{"schema":2,"terms":[]}',
    'unknown root field' => '{"schema":1,"terms":[],"typo":true}',
    'terms is an empty object' => '{"schema":1,"terms":{}}',
    'terms is a sequential object' => '{"schema":1,"terms":{"0":{"term":"x","category":"default","severity":2,"action":"flag","enabled":true,"metadata":[]}}}',
    'terms is not a list' => '{"schema":1,"terms":{"term":{"term":"x","category":"default","severity":2,"action":"flag","enabled":true,"metadata":[]}}}',
    'term is not an object' => '{"schema":1,"terms":["x"]}',
    'unknown term field' => '{"schema":1,"terms":[{"term":"x","category":"default","severity":2,"action":"flag","enabled":true,"metadata":[],"normalizedTerm":"x"}]}',
    'empty term' => '{"schema":1,"terms":[{"term":"","category":"default","severity":2,"action":"flag","enabled":true,"metadata":[]}]}',
    'empty category' => '{"schema":1,"terms":[{"term":"x","category":"","severity":2,"action":"flag","enabled":true,"metadata":[]}]}',
    'term has wrong type' => '{"schema":1,"terms":[{"term":1,"category":"default","severity":2,"action":"flag","enabled":true,"metadata":[]}]}',
    'severity has wrong type' => '{"schema":1,"terms":[{"term":"x","category":"default","severity":"2","action":"flag","enabled":true,"metadata":[]}]}',
    'severity is outside enum' => '{"schema":1,"terms":[{"term":"x","category":"default","severity":5,"action":"flag","enabled":true,"metadata":[]}]}',
    'action is outside enum' => '{"schema":1,"terms":[{"term":"x","category":"default","severity":2,"action":"warn","enabled":true,"metadata":[]}]}',
    'enabled has wrong type' => '{"schema":1,"terms":[{"term":"x","category":"default","severity":2,"action":"flag","enabled":1,"metadata":[]}]}',
    'metadata is not an array' => '{"schema":1,"terms":[{"term":"x","category":"default","severity":2,"action":"flag","enabled":true,"metadata":null}]}',
    'metadata has numeric outer key' => '{"schema":1,"terms":[{"term":"x","category":"default","severity":2,"action":"flag","enabled":true,"metadata":["manual"]}]}',
    'metadata is nested too deeply' => '{"schema":1,"terms":[{"term":"x","category":"default","severity":2,"action":"flag","enabled":true,"metadata":{"options":{"nested":{"again":true}}}}]}',
]);

it('wraps dictionary encoding failures', function () {
    expect(fn() => (new DictionaryJsonCodec())->encode([new SensitiveTerm("\xFF")]))
        ->toThrow(DictionaryException::class);
});

it('does not encode a term that violates the payload schema', function () {
    expect(fn() => (new DictionaryJsonCodec())->encode([new SensitiveTerm('')]))
        ->toThrow(DictionaryException::class);
});

it('loads the version and payload from one consistent pair during a publish race', function () {
    $codec = new DictionaryJsonCodec();
    $redis = new FakeRedisClient();
    $redis->seed('4', $codec->encode([new SensitiveTerm('旧词')]));
    $redis->afterReadSnapshot = static function (FakeRedisClient $client) use ($codec): void {
        $client->afterReadSnapshot = null;
        $client->seed('5', $codec->encode([new SensitiveTerm('新词')]));
    };
    $repository = new RedisDictionaryRepository($redis);

    $oldSnapshot = $repository->load();
    $newSnapshot = $repository->load();

    expect($oldSnapshot->version)->toBe('4')
        ->and($oldSnapshot->terms[0]->term)->toBe('旧词')
        ->and($newSnapshot->version)->toBe('5')
        ->and($newSnapshot->terms[0]->term)->toBe('新词')
        ->and($redis->getCalls)->toBe(0);
});

it('distinguishes a missing key from a valid empty dictionary', function () {
    $codec = new DictionaryJsonCodec();

    $missingBoth = new RedisDictionaryRepository(new FakeRedisClient());
    expect(fn() => $missingBoth->load())->toThrow(DictionaryException::class);

    $missingPayloadRedis = new FakeRedisClient();
    $missingPayloadRedis->seed('1', $codec->encode([]), dictionaryKey: 'other');
    expect(fn() => (new RedisDictionaryRepository($missingPayloadRedis))->load())
        ->toThrow(DictionaryException::class);

    $missingVersionRedis = new FakeRedisClient();
    $missingVersionRedis->seed('1', $codec->encode([]), versionKey: 'other:version');
    expect(fn() => (new RedisDictionaryRepository($missingVersionRedis))->load())
        ->toThrow(DictionaryException::class);

    $emptyRedis = new FakeRedisClient();
    $emptyRedis->seed('1', $codec->encode([]));
    expect((new RedisDictionaryRepository($emptyRedis))->load()->terms)->toBe([]);
});

it('validates versions read from redis', function (string $version) {
    $redis = new FakeRedisClient();
    $redis->seed($version, (new DictionaryJsonCodec())->encode([]));
    $repository = new RedisDictionaryRepository($redis);

    expect(fn() => $repository->version())->toThrow(DictionaryException::class)
        ->and(fn() => $repository->load())->toThrow(DictionaryException::class);
})->with([
    'empty' => '',
    'leading zero' => '01',
    'negative' => '-1',
    'non decimal' => '1.0',
    'over nineteen digits' => '10000000000000000000',
    'above signed maximum' => '9223372036854775808',
]);

it('accepts the largest readable version without converting it to an integer', function () {
    $redis = new FakeRedisClient();
    $redis->seed('9223372036854775807', (new DictionaryJsonCodec())->encode([]));
    $repository = new RedisDictionaryRepository($redis);

    expect($repository->version())->toBe('9223372036854775807')
        ->and($repository->load()->version)->toBe('9223372036854775807')
        ->and($redis->getCalls)->toBe(1);
});

it('uses the configured prefix and key names', function () {
    $redis = new FakeRedisClient();
    $repository = new RedisDictionaryRepository($redis, 'tenant:42:', 'terms', 'revision');

    expect($repository->publish([new SensitiveTerm('租户词')], null))->toBe('1')
        ->and($repository->version())->toBe('1')
        ->and($repository->load()->terms[0]->term)->toBe('租户词');
});

it('does not alter either key when the maximum version overflows', function () {
    $codec = new DictionaryJsonCodec();
    $redis = new FakeRedisClient();
    $originalPayload = $codec->encode([new SensitiveTerm('保留词')]);
    $redis->seed('9223372036854775807', $originalPayload);
    $repository = new RedisDictionaryRepository($redis);

    expect(fn() => $repository->publish([new SensitiveTerm('新词')], '9223372036854775807'))
        ->toThrow(DictionaryException::class)
        ->and($redis->readSnapshot('sensitive_text:dictionary:version', 'sensitive_text:dictionary'))
        ->toBe(['9223372036854775807', $originalPayload]);
});

it('propagates injected redis failures', function () {
    $redis = new FakeRedisClient();
    $redis->fail = true;
    $repository = new RedisDictionaryRepository($redis);

    expect(fn() => $repository->version())->toThrow(DictionaryException::class)
        ->and(fn() => $repository->load())->toThrow(DictionaryException::class)
        ->and(fn() => $repository->publish([], null))->toThrow(DictionaryException::class);
});
