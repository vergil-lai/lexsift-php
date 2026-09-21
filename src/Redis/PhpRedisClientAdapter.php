<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Redis;

use Redis;
use VergilLai\SensitiveText\Contracts\RedisClientInterface;
use VergilLai\SensitiveText\Exception\RedisUnavailableException;

final class PhpRedisClientAdapter implements RedisClientInterface
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

    public function __construct(private readonly Redis $client) {}

    public function get(string $key): ?string
    {
        $raw = $this->execute('GET', fn(): mixed => $this->client->get($key));
        if (false === $raw || null === $raw) {
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
            fn(): mixed => $this->client->eval(self::READ_SNAPSHOT_SCRIPT, [$versionKey, $dictionaryKey], 2),
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
            fn(): mixed => $this->client->eval(
                self::COMPARE_AND_SWAP_SCRIPT,
                [$versionKey, $dictionaryKey, $expectedVersion ?? '', $payload],
                2,
            ),
        );

        if (false === $raw || null === $raw) {
            return null;
        }

        if (!is_string($raw) || 1 !== preg_match('/^[0-9]+$/D', $raw)) {
            throw new RedisUnavailableException('Redis compare-and-swap script returned an unexpected result.');
        }

        return $raw;
    }

    private function execute(string $operation, callable $command): mixed
    {
        try {
            $this->client->clearLastError();
            $result = $command();
            $error = $this->client->getLastError();
        } catch (\Throwable $exception) {
            throw new RedisUnavailableException("Redis {$operation} failed.", previous: $exception);
        }

        if (null !== $error) {
            throw new RedisUnavailableException("Redis {$operation} failed: {$error}");
        }

        return $result;
    }

    private function nullableString(mixed $value, string $operation): ?string
    {
        if (false === $value || null === $value) {
            return null;
        }

        if (!is_string($value)) {
            throw new RedisUnavailableException("Redis {$operation} returned an unexpected result.");
        }

        return $value;
    }
}
