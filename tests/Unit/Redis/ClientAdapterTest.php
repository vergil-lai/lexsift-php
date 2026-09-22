<?php

declare(strict_types=1);

use VergilLai\SensitiveText\Exception\InvalidConfigurationException;
use VergilLai\SensitiveText\Exception\RedisUnavailableException;
use VergilLai\SensitiveText\Redis\PhpRedisClientAdapter;
use VergilLai\SensitiveText\Tests\Helpers\StubPhpRedisClient;

it('narrows get results to nullable strings', function () {
    $client = new StubPhpRedisClient();
    $client->getResults = [false, '7'];
    $adapter = new PhpRedisClientAdapter($client);

    expect($adapter->get('missing'))->toBeNull()
        ->and($adapter->get('version'))->toBe('7')
        ->and($client->getCalls)->toBe(['missing', 'version'])
        ->and($client->clearLastErrorCalls)->toBe(2)
        ->and($client->getLastErrorCalls)->toBe(2);
});

it('reads a snapshot with one atomic script call', function () {
    $client = new StubPhpRedisClient();
    $client->evalResults = [[false, '{"schema":1,"terms":[]}']];

    expect((new PhpRedisClientAdapter($client))->readSnapshot('version', 'dictionary'))
        ->toBe([null, '{"schema":1,"terms":[]}'])
        ->and($client->evalCalls)->toHaveCount(1)
        ->and($client->evalCalls[0]['script'])->toContain("redis.call('GET', KEYS[1])")
        ->and($client->evalCalls[0]['script'])->toContain("redis.call('GET', KEYS[2])")
        ->and($client->evalCalls[0]['args'])->toBe(['version', 'dictionary'])
        ->and($client->evalCalls[0]['numKeys'])->toBe(2);
});

it('publishes with the atomic compare-and-swap script', function () {
    $client = new StubPhpRedisClient();
    $client->evalResults = ['8'];

    expect((new PhpRedisClientAdapter($client))->compareAndSwap(
        'version',
        'dictionary',
        '7',
        '{"schema":1,"terms":[]}',
    ))->toBe('8')
        ->and($client->evalCalls)->toHaveCount(1)
        ->and($client->evalCalls[0]['script'])->toContain("redis.call('GET', KEYS[1])")
        ->and($client->evalCalls[0]['script'])->toContain("redis.call('MSET', KEYS[1], nextVersion, KEYS[2], ARGV[2])")
        ->and($client->evalCalls[0]['script'])->toContain('return nextVersion')
        ->and($client->evalCalls[0]['args'])->toBe([
            'version',
            'dictionary',
            '7',
            '{"schema":1,"terms":[]}',
        ])
        ->and($client->evalCalls[0]['numKeys'])->toBe(2);
});

it('encodes a null expected version and returns null on compare-and-swap conflict', function (mixed $result) {
    $client = new StubPhpRedisClient();
    $client->evalResults = [$result];

    expect((new PhpRedisClientAdapter($client))->compareAndSwap(
        'version',
        'dictionary',
        null,
        'payload',
    ))->toBeNull()
        ->and($client->evalCalls[0]['args'])->toBe(['version', 'dictionary', '', 'payload']);
})->with([
    'false' => [false],
    'null' => [null],
]);

it('wraps phpredis exceptions and preserves the cause', function () {
    $cause = new RedisException('Redis server went away');
    $client = new StubPhpRedisClient();
    $client->getException = $cause;

    try {
        (new PhpRedisClientAdapter($client))->get('version');
        PHPUnit\Framework\Assert::fail('Expected RedisUnavailableException was not thrown.');
    } catch (RedisUnavailableException $exception) {
        expect($exception->getPrevious())->toBe($cause)
            ->and($client->getLastErrorCalls)->toBe(0);
    }
});

it('raises the phpredis error state instead of treating false as a missing key', function () {
    $client = new StubPhpRedisClient();
    $client->getResults = [false];
    $client->commandError = 'NOAUTH Authentication required.';

    expect(fn() => (new PhpRedisClientAdapter($client))->get('version'))
        ->toThrow(RedisUnavailableException::class, 'NOAUTH Authentication required.');
});

it('raises script errors instead of treating false as a compare-and-swap conflict', function () {
    $client = new StubPhpRedisClient();
    $client->evalResults = [false];
    $client->commandError = 'NOPERM this user has no permissions to run EVAL';

    expect(fn() => (new PhpRedisClientAdapter($client))->compareAndSwap(
        'version',
        'dictionary',
        null,
        'payload',
    ))->toThrow(RedisUnavailableException::class, 'NOPERM');
});

it('rejects malformed snapshot results', function (mixed $result) {
    $client = new StubPhpRedisClient();
    $client->evalResults = [$result];

    expect(fn() => (new PhpRedisClientAdapter($client))->readSnapshot('version', 'dictionary'))
        ->toThrow(RedisUnavailableException::class);
})->with([
    'not an array' => 'payload',
    'one element' => ['1'],
    'three elements' => ['1', 'payload', 'extra'],
    'invalid version type' => [1, 'payload'],
    'invalid payload type' => ['1', []],
]);

it('rejects an associative snapshot pair', function () {
    $client = new StubPhpRedisClient();
    $client->evalResults = [['version' => '1', 'payload' => 'payload']];

    expect(fn() => (new PhpRedisClientAdapter($client))->readSnapshot('version', 'dictionary'))
        ->toThrow(RedisUnavailableException::class);
});

it('rejects unexpected get and compare-and-swap success results', function (string $operation, mixed $result) {
    $client = new StubPhpRedisClient();
    $adapter = new PhpRedisClientAdapter($client);

    if ('get' === $operation) {
        $client->getResults = [$result];
        $call = fn() => $adapter->get('version');
    } else {
        $client->evalResults = [$result];
        $call = fn() => $adapter->compareAndSwap('version', 'dictionary', null, 'payload');
    }

    expect($call)->toThrow(RedisUnavailableException::class);
})->with([
    'integer GET result' => ['get', 1],
    'integer script result' => ['compare-and-swap', 1],
    'non-numeric script result' => ['compare-and-swap', 'ok'],
]);

it('connects lazily with tls authentication database and both timeouts', function () {
    $client = new StubPhpRedisClient();
    $client->getResults = ['7'];
    $adapter = PhpRedisClientAdapter::fromUrl(
        'tls://app%20user:s3cr3t%21@redis.example.com:6380/4',
        1.25,
    );
    $replaceClient = Closure::bind(
        static function (PhpRedisClientAdapter $adapter, Redis $client): void {
            $adapter->client = $client;
        },
        null,
        PhpRedisClientAdapter::class,
    );
    $replaceClient($adapter, $client);

    expect($client->connectCalls)->toBe([])
        ->and($adapter->get('version'))->toBe('7')
        ->and($client->connectCalls)->toBe([[
            'host' => 'tls://redis.example.com',
            'port' => 6380,
            'timeout' => 1.25,
            'persistentId' => null,
            'retryInterval' => 0,
            'readTimeout' => 1.25,
            'context' => null,
        ]])
        ->and($client->authCalls)->toBe([['app user', 's3cr3t!']])
        ->and($client->selectCalls)->toBe([4]);
});

it('supports password-only authentication and skips the default database selection', function () {
    $client = new StubPhpRedisClient();
    $client->getResults = [false];
    $adapter = PhpRedisClientAdapter::fromUrl('tcp://:secret@localhost/0', 0.5);
    $replaceClient = Closure::bind(
        static function (PhpRedisClientAdapter $adapter, Redis $client): void {
            $adapter->client = $client;
        },
        null,
        PhpRedisClientAdapter::class,
    );
    $replaceClient($adapter, $client);

    expect($adapter->get('missing'))->toBeNull()
        ->and($client->connectCalls)->toBe([[
            'host' => 'localhost',
            'port' => 6379,
            'timeout' => 0.5,
            'persistentId' => null,
            'retryInterval' => 0,
            'readTimeout' => 0.5,
            'context' => null,
        ]])
        ->and($client->authCalls)->toBe(['secret'])
        ->and($client->selectCalls)->toBe([]);
});

it('rejects invalid redis urls without opening a connection', function (string $url) {
    expect(fn() => PhpRedisClientAdapter::fromUrl($url, 1.0))
        ->toThrow(InvalidConfigurationException::class);
})->with([
    'unsupported scheme' => 'redis://localhost:6379',
    'missing host' => 'tcp:///1',
    'invalid port' => 'tcp://localhost:70000',
    'invalid database' => 'tcp://localhost/not-a-number',
    'negative database' => 'tcp://localhost/-1',
    'database overflow' => 'tcp://localhost/9223372036854775808',
    'username without password' => 'tcp://user@localhost/0',
    'query parameters' => 'tcp://localhost/0?timeout=2',
]);

it('rejects invalid redis timeouts', function (float $timeout) {
    expect(fn() => PhpRedisClientAdapter::fromUrl('tcp://localhost', $timeout))
        ->toThrow(InvalidConfigurationException::class);
})->with([
    'zero' => [0.0],
    'negative' => [-1.0],
    'infinite' => [INF],
    'not a number' => [NAN],
]);

it('does not expose redis credentials when connection setup fails', function () {
    $client = new StubPhpRedisClient();
    $client->authResult = false;
    $url = 'tcp://private-user:private-password@redis.example.com:6379/2';
    $adapter = PhpRedisClientAdapter::fromUrl($url, 1.0);
    $replaceClient = Closure::bind(
        static function (PhpRedisClientAdapter $adapter, Redis $client): void {
            $adapter->client = $client;
        },
        null,
        PhpRedisClientAdapter::class,
    );
    $replaceClient($adapter, $client);

    try {
        $adapter->get('version');
        PHPUnit\Framework\Assert::fail('Expected RedisUnavailableException was not thrown.');
    } catch (RedisUnavailableException $exception) {
        expect($exception->getMessage())->not->toContain($url)
            ->and($exception->getMessage())->not->toContain('private-password');
    }
});
