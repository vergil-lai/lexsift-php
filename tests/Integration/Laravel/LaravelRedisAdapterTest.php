<?php

declare(strict_types=1);

use VergilLai\SensitiveText\Exception\InvalidConfigurationException;
use VergilLai\SensitiveText\Exception\RedisUnavailableException;
use VergilLai\SensitiveText\Laravel\LaravelRedisAdapter;
use VergilLai\SensitiveText\Tests\Helpers\StubLaravelRedisConnection;

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
    expect(fn() => new LaravelRedisAdapter(new stdClass()))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn() => LaravelRedisAdapter::lazy(static fn(): object => new stdClass())->get('key'))
        ->toThrow(InvalidConfigurationException::class);
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
