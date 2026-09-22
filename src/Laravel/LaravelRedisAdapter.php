<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Laravel;

use Closure;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Redis;
use VergilLai\SensitiveText\Contracts\RedisClientInterface;
use VergilLai\SensitiveText\Exception\InvalidConfigurationException;
use VergilLai\SensitiveText\Exception\RedisUnavailableException;

final class LaravelRedisAdapter implements RedisClientInterface
{
    private const READ_SNAPSHOT_SCRIPT = <<<'LUA'
return {redis.call('GET', KEYS[1]), redis.call('GET', KEYS[2])}
LUA;

    private const COMPARE_AND_SWAP_SCRIPT = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if (current or '') ~= ARGV[1] then return false end
local value = current or '0'
if not string.match(value, '^%d+$') or (#value > 1 and string.sub(value, 1, 1) == '0') then
  return redis.error_reply('Invalid dictionary version')
end
if #value > 19 or (#value == 19 and value >= '9223372036854775807') then
  return redis.error_reply('Dictionary version overflow')
end
local digits = {}
local carry = 1
for i = #value, 1, -1 do
  local digit = tonumber(string.sub(value, i, i)) + carry
  if digit == 10 then digit = 0; carry = 1 else carry = 0 end
  digits[i] = tostring(digit)
end
local nextVersion = (carry == 1 and '1' or '') .. table.concat(digits)
redis.call('MSET', KEYS[1], nextVersion, KEYS[2], ARGV[2])
return nextVersion
LUA;

    private ?PhpRedisConnection $connection = null;

    private ?Closure $resolver = null;

    public function __construct(mixed $connection)
    {
        if ($connection instanceof PhpRedisConnection) {
            $this->connection = $this->validateConnection($connection);

            return;
        }
        if (is_callable($connection)) {
            $this->resolver = Closure::fromCallable($connection);

            return;
        }

        throw new InvalidConfigurationException('Laravel Redis connection must use the phpredis driver.');
    }

    public static function lazy(callable $resolver): self
    {
        return new self($resolver);
    }

    public function get(string $key): ?string
    {
        $raw = $this->execute('GET', static fn(PhpRedisConnection $connection): mixed => $connection->get($key));
        if (null === $raw || false === $raw) {
            return null;
        }
        if (!is_string($raw)) {
            throw new RedisUnavailableException('Redis GET returned an unexpected result.');
        }

        return $raw;
    }

    public function readSnapshot(string $versionKey, string $dictionaryKey): array
    {
        $raw = $this->execute(
            'snapshot script',
            static fn(PhpRedisConnection $connection): mixed => $connection->eval(
                self::READ_SNAPSHOT_SCRIPT,
                2,
                $versionKey,
                $dictionaryKey,
            ),
        );
        if (!is_array($raw) || !array_is_list($raw) || 2 !== count($raw)) {
            throw new RedisUnavailableException('Redis snapshot script returned an unexpected result.');
        }

        return [
            $this->nullableString($raw[0] ?? null, 'snapshot version'),
            $this->nullableString($raw[1] ?? null, 'snapshot payload'),
        ];
    }

    public function compareAndSwap(
        string $versionKey,
        string $dictionaryKey,
        ?string $expectedVersion,
        string $payload,
    ): ?string {
        $raw = $this->execute(
            'compare-and-swap script',
            static fn(PhpRedisConnection $connection): mixed => $connection->eval(
                self::COMPARE_AND_SWAP_SCRIPT,
                2,
                $versionKey,
                $dictionaryKey,
                $expectedVersion ?? '',
                $payload,
            ),
        );
        if (null === $raw || false === $raw) {
            return null;
        }
        if (!is_string($raw) || 1 !== preg_match('/^[0-9]+$/D', $raw)) {
            throw new RedisUnavailableException('Redis compare-and-swap script returned an unexpected result.');
        }

        return $raw;
    }

    private function resolveConnection(string $operation): PhpRedisConnection
    {
        if (null !== $this->connection) {
            return $this->connection;
        }

        $resolver = $this->resolver ?? throw new InvalidConfigurationException(
            'Laravel Redis connection resolver is missing.',
        );
        try {
            $connection = $resolver();
        } catch (\Throwable $exception) {
            throw $this->unavailableException($operation, $exception);
        }

        try {
            return $this->connection = $this->validateConnection($connection);
        } catch (InvalidConfigurationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw $this->unavailableException($operation, $exception);
        }
    }

    /** @param callable(PhpRedisConnection): mixed $command */
    private function execute(string $operation, callable $command): mixed
    {
        $connection = $this->resolveConnection($operation);
        try {
            $client = $this->phpRedisClient($connection);
            $client->clearLastError();
            $result = $command($connection);
            $error = $this->phpRedisClient($connection)->getLastError();
        } catch (\Throwable $exception) {
            throw $this->unavailableException($operation, $exception);
        }

        if (null !== $error) {
            throw new RedisUnavailableException(
                "Redis {$operation} failed.",
                previous: new \RuntimeException('Redis server reported an error.'),
            );
        }

        return $result;
    }

    private function unavailableException(string $operation, \Throwable $exception): RedisUnavailableException
    {
        return new RedisUnavailableException(
            "Redis {$operation} failed.",
            previous: new \RuntimeException(sprintf('Redis dependency raised %s.', $exception::class)),
        );
    }

    private function nullableString(mixed $value, string $operation): ?string
    {
        if (null === $value || false === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new RedisUnavailableException("Redis {$operation} returned an unexpected result.");
        }

        return $value;
    }

    private function validateConnection(mixed $connection): PhpRedisConnection
    {
        if (!$connection instanceof PhpRedisConnection || $connection instanceof PhpRedisClusterConnection) {
            throw new InvalidConfigurationException('Laravel Redis connection must use the phpredis driver.');
        }
        $this->phpRedisClient($connection);

        return $connection;
    }

    private function phpRedisClient(PhpRedisConnection $connection): Redis
    {
        $client = $connection->client();
        if (!$client instanceof Redis) {
            throw new InvalidConfigurationException('Laravel Redis connection must wrap a phpredis Redis client.');
        }

        return $client;
    }
}
