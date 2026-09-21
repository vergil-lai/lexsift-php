<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Contracts;

interface RedisClientInterface
{
    public function get(string $key): ?string;

    /** @return array{0: ?string, 1: ?string} */
    public function readSnapshot(string $versionKey, string $dictionaryKey): array;

    /** Returns the new version, or null on compare-and-swap conflict. */
    public function compareAndSwap(
        string $versionKey,
        string $dictionaryKey,
        ?string $expectedVersion,
        string $payload,
    ): ?string;
}
