<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Tests\Helpers;

use Closure;
use VergilLai\SensitiveText\Contracts\RedisClientInterface;
use VergilLai\SensitiveText\Exception\DictionaryException;

final class FakeRedisClient implements RedisClientInterface
{
    /** @var array<string, string> */
    private array $values = [];

    public bool $fail = false;

    public int $getCalls = 0;

    public ?Closure $afterReadSnapshot = null;

    public function get(string $key): ?string
    {
        ++$this->getCalls;
        $this->throwIfFailed();

        return $this->values[$key] ?? null;
    }

    public function readSnapshot(string $versionKey, string $dictionaryKey): array
    {
        $this->throwIfFailed();

        $snapshot = [$this->values[$versionKey] ?? null, $this->values[$dictionaryKey] ?? null];
        if (null !== $this->afterReadSnapshot) {
            ($this->afterReadSnapshot)($this);
        }

        return $snapshot;
    }

    public function compareAndSwap(
        string $versionKey,
        string $dictionaryKey,
        ?string $expectedVersion,
        string $payload,
    ): ?string {
        $this->throwIfFailed();

        $current = $this->values[$versionKey] ?? null;
        if ($current !== $expectedVersion) {
            return null;
        }

        $nextVersion = $this->incrementVersion($current ?? '0');
        $this->values[$versionKey] = $nextVersion;
        $this->values[$dictionaryKey] = $payload;

        return $nextVersion;
    }

    public function seed(
        string $version,
        string $payload,
        string $prefix = 'sensitive_text:',
        string $dictionaryKey = 'dictionary',
        string $versionKey = 'dictionary:version',
    ): void {
        $this->values[$prefix . $versionKey] = $version;
        $this->values[$prefix . $dictionaryKey] = $payload;
    }

    private function incrementVersion(string $version): string
    {
        if (1 !== preg_match('/^(0|[1-9][0-9]{0,18})$/D', $version)
            || (19 === strlen($version) && strcmp($version, '9223372036854775807') >= 0)) {
            throw new DictionaryException('Invalid or overflowing dictionary version.');
        }

        $digits = str_split($version);
        $carry = 1;
        for ($index = count($digits) - 1; $index >= 0; --$index) {
            $digit = ord($digits[$index]) - 48 + $carry;
            if (10 === $digit) {
                $digits[$index] = '0';
            } else {
                $digits[$index] = (string) $digit;
                $carry = 0;
                break;
            }
        }

        return (1 === $carry ? '1' : '') . implode('', $digits);
    }

    private function throwIfFailed(): void
    {
        if ($this->fail) {
            throw new DictionaryException('Injected Redis failure.');
        }
    }
}
