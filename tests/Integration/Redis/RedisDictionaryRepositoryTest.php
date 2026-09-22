<?php

declare(strict_types=1);

use VergilLai\SensitiveText\Dictionary\RedisDictionaryRepository;
use VergilLai\SensitiveText\Dictionary\SensitiveTerm;
use VergilLai\SensitiveText\Exception\DictionaryException;
use VergilLai\SensitiveText\Exception\RedisUnavailableException;
use VergilLai\SensitiveText\Redis\PhpRedisClientAdapter;

$redisIntegrationDisabled = '1' !== getenv('SENSITIVE_TEXT_REDIS_TESTS');

$connect = static function (?string $username = null, ?string $password = null): Redis {
    $client = new Redis();
    $connected = $client->connect(
        getenv('SENSITIVE_TEXT_REDIS_HOST') ?: '127.0.0.1',
        (int) (getenv('SENSITIVE_TEXT_REDIS_PORT') ?: 6379),
        1.0,
    );
    if (true !== $connected) {
        throw new RuntimeException('Failed to connect to the Redis integration server.');
    }

    if (null !== $username && null !== $password && true !== $client->auth([$username, $password])) {
        throw new RuntimeException('Failed to authenticate to the Redis integration server.');
    }

    return $client;
};

$deleteKeys = static function (Redis $client, string $prefix): void {
    $client->del([$prefix . 'dictionary', $prefix . 'dictionary:version']);
};

$randomHex = static fn(int $length): string => bin2hex((new Random\Randomizer())->getBytes($length));

it('round trips one atomic snapshot with a custom prefix', function () use ($connect, $deleteKeys, $randomHex) {
    $client = $connect();
    $prefix = 'sensitive_text:test:' . $randomHex(8) . ':';

    try {
        $adapter = new PhpRedisClientAdapter($client);
        $repository = new RedisDictionaryRepository($adapter, $prefix);
        $version = $repository->publish([new SensitiveTerm('微信')], null);
        [$snapshotVersion, $payload] = $adapter->readSnapshot(
            $prefix . 'dictionary:version',
            $prefix . 'dictionary',
        );
        $snapshot = $repository->load();

        expect($version)->toBe('1')
            ->and($snapshotVersion)->toBe('1')
            ->and($payload)->toBeString()
            ->and($snapshot->version)->toBe('1')
            ->and($snapshot->terms[0]->term)->toBe('微信');
    } finally {
        $deleteKeys($client, $prefix);
        $client->close();
    }
})->skip($redisIntegrationDisabled, 'Set SENSITIVE_TEXT_REDIS_TESTS=1 for Redis integration');

it('distinguishes a missing snapshot from a valid empty dictionary', function () use ($connect, $deleteKeys, $randomHex) {
    $client = $connect();
    $prefix = 'sensitive_text:test:' . $randomHex(8) . ':';
    $repository = new RedisDictionaryRepository(new PhpRedisClientAdapter($client), $prefix);

    try {
        expect(fn() => $repository->load())->toThrow(DictionaryException::class)
            ->and($repository->publish([], null))->toBe('1')
            ->and($repository->load()->terms)->toBe([]);
    } finally {
        $deleteKeys($client, $prefix);
        $client->close();
    }
})->skip($redisIntegrationDisabled, 'Set SENSITIVE_TEXT_REDIS_TESTS=1 for Redis integration');

it('rejects a stale publish from a second client without changing the snapshot', function () use ($connect, $deleteKeys, $randomHex) {
    $firstClient = $connect();
    $secondClient = $connect();
    $prefix = 'sensitive_text:test:' . $randomHex(8) . ':';
    $first = new RedisDictionaryRepository(new PhpRedisClientAdapter($firstClient), $prefix);
    $second = new RedisDictionaryRepository(new PhpRedisClientAdapter($secondClient), $prefix);

    try {
        expect($first->publish([new SensitiveTerm('第一版')], null))->toBe('1')
            ->and($second->publish([new SensitiveTerm('第二版')], '1'))->toBe('2')
            ->and(fn() => $first->publish([new SensitiveTerm('过期写入')], '1'))
            ->toThrow(DictionaryException::class)
            ->and($first->load()->version)->toBe('2')
            ->and($first->load()->terms[0]->term)->toBe('第二版');
    } finally {
        $deleteKeys($firstClient, $prefix);
        $firstClient->close();
        $secondClient->close();
    }
})->skip($redisIntegrationDisabled, 'Set SENSITIVE_TEXT_REDIS_TESTS=1 for Redis integration');

it('wraps a command on a disconnected socket', function () use ($connect) {
    $client = $connect();
    $killer = $connect();
    $client->setOption(Redis::OPT_MAX_RETRIES, 0);
    $clientId = $client->rawCommand('CLIENT', 'ID');
    if (!is_int($clientId)) {
        throw new RuntimeException('Redis CLIENT ID returned an unexpected result.');
    }
    expect($killer->rawCommand('CLIENT', 'KILL', 'ID', (string) $clientId))->toBe(1);

    try {
        (new PhpRedisClientAdapter($client))->get('sensitive_text:test:disconnected');
        PHPUnit\Framework\Assert::fail('Expected RedisUnavailableException was not thrown.');
    } catch (RedisUnavailableException $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(RedisException::class);
    } finally {
        $client->close();
        $killer->close();
    }
})->skip($redisIntegrationDisabled, 'Set SENSITIVE_TEXT_REDIS_TESTS=1 for Redis integration');

it('wraps a real Redis ACL error when scripts are disabled', function () use ($connect, $deleteKeys, $randomHex) {
    $admin = $connect();
    $restricted = null;
    $prefix = 'sensitive_text:test:' . $randomHex(8) . ':';
    $username = 'sensitive_text_test_' . $randomHex(8);
    $password = $randomHex(16);

    try {
        expect($admin->rawCommand(
            'ACL',
            'SETUSER',
            $username,
            'reset',
            'on',
            '>' . $password,
            '~' . $prefix . '*',
            '+get',
            '+mset',
        ))->toBeTrue();

        $restricted = $connect($username, $password);

        try {
            (new PhpRedisClientAdapter($restricted))->readSnapshot(
                $prefix . 'dictionary:version',
                $prefix . 'dictionary',
            );
            PHPUnit\Framework\Assert::fail('Expected RedisUnavailableException was not thrown.');
        } catch (RedisUnavailableException $exception) {
            $messages = $exception->getMessage() . ' ' . ($exception->getPrevious()?->getMessage() ?? '');
            expect($messages)->toContain('NOPERM');
        }
    } finally {
        $restricted?->close();
        $deleteKeys($admin, $prefix);
        $admin->rawCommand('ACL', 'DELUSER', $username);
        $admin->close();
    }
})->skip($redisIntegrationDisabled, 'Set SENSITIVE_TEXT_REDIS_TESTS=1 for Redis integration');
