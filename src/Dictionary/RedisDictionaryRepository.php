<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Dictionary;

use VergilLai\SensitiveText\Contracts\DictionaryRepositoryInterface;
use VergilLai\SensitiveText\Contracts\RedisClientInterface;
use VergilLai\SensitiveText\Exception\DictionaryException;

final class RedisDictionaryRepository implements DictionaryRepositoryInterface
{
    private readonly DictionaryJsonCodec $codec;

    private readonly string $dictionaryKey;

    private readonly string $versionKey;

    public function __construct(
        private readonly RedisClientInterface $redis,
        string $prefix = 'sensitive_text:',
        string $dictionaryKey = 'dictionary',
        string $versionKey = 'dictionary:version',
    ) {
        $this->codec = new DictionaryJsonCodec();
        $this->dictionaryKey = $prefix . $dictionaryKey;
        $this->versionKey = $prefix . $versionKey;
    }

    public function version(): string
    {
        $version = $this->redis->get($this->versionKey);
        if (null === $version) {
            throw new DictionaryException('Dictionary version is missing.');
        }

        return $this->validateVersion($version);
    }

    public function load(): SensitiveDictionary
    {
        [$version, $payload] = $this->redis->readSnapshot($this->versionKey, $this->dictionaryKey);
        if (null === $version || null === $payload) {
            throw new DictionaryException('Dictionary snapshot is incomplete.');
        }

        return new SensitiveDictionary($this->validateVersion($version), $this->codec->decode($payload));
    }

    /** @param list<SensitiveTerm> $terms */
    public function publish(array $terms, ?string $expectedVersion): string
    {
        if (null !== $expectedVersion) {
            $this->validateVersion($expectedVersion);
        }

        $version = $this->redis->compareAndSwap(
            $this->versionKey,
            $this->dictionaryKey,
            $expectedVersion,
            $this->codec->encode($terms),
        );

        if (null === $version) {
            throw new DictionaryException('Dictionary publish conflict.');
        }

        return $this->validateVersion($version);
    }

    private function validateVersion(string $version): string
    {
        if (1 !== preg_match('/^(0|[1-9][0-9]{0,18})$/D', $version)
            || (19 === strlen($version) && strcmp($version, '9223372036854775807') > 0)) {
            throw new DictionaryException('Invalid dictionary version.');
        }

        return $version;
    }
}
