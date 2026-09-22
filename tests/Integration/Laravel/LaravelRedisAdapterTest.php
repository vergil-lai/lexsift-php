<?php

declare(strict_types=1);

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use VergilLai\SensitiveText\Exception\InvalidConfigurationException;
use VergilLai\SensitiveText\Exception\RedisUnavailableException;
use VergilLai\SensitiveText\Laravel\LaravelRedisAdapter;
use VergilLai\SensitiveText\Tests\Helpers\StubLaravelRedisConnection;
use VergilLai\SensitiveText\Tests\Helpers\StubPhpRedisClient;

it('maps get and scripts to the Laravel phpredis connection API', function () {
    $connection = new StubLaravelRedisConnection([
        '7',
        ['7', '{"schema":1,"terms":[]}'],
        '8',
    ]);
    $adapter = new LaravelRedisAdapter($connection);

    expect($adapter->get('version'))->toBe('7')
        ->and($adapter->readSnapshot('version', 'dictionary'))->toBe(['7', '{"schema":1,"terms":[]}'])
        ->and($adapter->compareAndSwap('version', 'dictionary', '7', 'payload'))->toBe('8')
        ->and($connection->commands[0])->toBe([
            'method' => 'get',
            'parameters' => ['version'],
        ])
        ->and($connection->commands[1]['method'])->toBe('eval')
        ->and($connection->commands[1]['parameters'][1])->toBe(['version', 'dictionary'])
        ->and($connection->commands[1]['parameters'][2])->toBe(2)
        ->and($connection->commands[2]['method'])->toBe('eval')
        ->and($connection->commands[2]['parameters'][1])->toBe([
            'version',
            'dictionary',
            '7',
            'payload',
        ])
        ->and($connection->commands[2]['parameters'][2])->toBe(2);
});

it('resolves and validates a lazy phpredis connection only once', function () {
    $calls = 0;
    $connection = new StubLaravelRedisConnection(['1', '2']);
    $adapter = LaravelRedisAdapter::lazy(function () use (&$calls, $connection): mixed {
        ++$calls;

        return $connection;
    });

    expect($calls)->toBe(0)
        ->and($adapter->get('first'))->toBe('1')
        ->and($adapter->get('second'))->toBe('2')
        ->and($calls)->toBe(1);
});

it('rejects direct and lazily resolved non-phpredis connections', function () {
    $cluster = (new ReflectionClass(RedisCluster::class))->newInstanceWithoutConstructor();
    $invalidClientConnection = new PhpRedisConnection(new Redis());
    $clusterConnection = new PhpRedisClusterConnection(new Redis());
    $clientProperty = new ReflectionProperty(Connection::class, 'client');
    $clientProperty->setValue($invalidClientConnection, new stdClass());
    $clientProperty->setValue($clusterConnection, $cluster);
    $invalid = [
        new stdClass(),
        (new ReflectionClass(PredisConnection::class))->newInstanceWithoutConstructor(),
        $invalidClientConnection,
        $clusterConnection,
    ];

    foreach ($invalid as $connection) {
        expect(fn() => new LaravelRedisAdapter($connection))
            ->toThrow(InvalidConfigurationException::class)
            ->and(fn() => LaravelRedisAdapter::lazy(static fn(): object => $connection)->get('key'))
            ->toThrow(InvalidConfigurationException::class);
    }
});

it('wraps Laravel Redis command failures without exposing their message', function () {
    $connection = new StubLaravelRedisConnection([new RuntimeException('secret endpoint')]);

    try {
        (new LaravelRedisAdapter($connection))->get('key');
        PHPUnit\Framework\Assert::fail('Expected RedisUnavailableException was not thrown.');
    } catch (RedisUnavailableException $exception) {
        expect($exception->getMessage())->toBe('Redis GET failed.')
            ->and($exception->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }
});

it('distinguishes a missing GET value from a phpredis error', function () {
    $client = new StubPhpRedisClient();
    $client->getResults = [false];
    $adapter = new LaravelRedisAdapter(new PhpRedisConnection($client));

    expect($adapter->get('missing'))->toBeNull()
        ->and($client->clearLastErrorCalls)->toBe(1)
        ->and($client->getLastErrorCalls)->toBe(1);

    $client->getResults = [false];
    $client->commandError = 'NOAUTH private credential';
    try {
        $adapter->get('missing');
        PHPUnit\Framework\Assert::fail('Expected RedisUnavailableException was not thrown.');
    } catch (RedisUnavailableException $exception) {
        expect($exception->getMessage())->toBe('Redis GET failed.')
            ->and($exception->getMessage())->not->toContain('private credential')
            ->and($exception->getPrevious())->toBeInstanceOf(RuntimeException::class)
            ->and($exception->getPrevious()?->getMessage())->not->toContain('private credential');
    }
});

it('distinguishes a missing snapshot from a phpredis script error', function () {
    $client = new StubPhpRedisClient();
    $client->evalResults = [[false, false]];
    $adapter = new LaravelRedisAdapter(new PhpRedisConnection($client));

    expect($adapter->readSnapshot('version', 'dictionary'))->toBe([null, null])
        ->and($client->clearLastErrorCalls)->toBe(1)
        ->and($client->getLastErrorCalls)->toBe(1);

    $client->evalResults = [false];
    $client->commandError = 'NOPERM private ACL detail';
    expect(fn() => $adapter->readSnapshot('version', 'dictionary'))
        ->toThrow(RedisUnavailableException::class, 'Redis snapshot script failed.');
});

it('distinguishes a compare-and-swap conflict from a phpredis script error', function () {
    $client = new StubPhpRedisClient();
    $client->evalResults = [false];
    $adapter = new LaravelRedisAdapter(new PhpRedisConnection($client));

    expect($adapter->compareAndSwap('version', 'dictionary', '7', 'payload'))->toBeNull()
        ->and($client->clearLastErrorCalls)->toBe(1)
        ->and($client->getLastErrorCalls)->toBe(1);

    $client->evalResults = [false];
    $client->commandError = 'ERR private Lua detail';
    expect(fn() => $adapter->compareAndSwap('version', 'dictionary', '7', 'payload'))
        ->toThrow(RedisUnavailableException::class, 'Redis compare-and-swap script failed.');
});
