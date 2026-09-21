<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Tests\Helpers;

use Closure;
use VergilLai\SensitiveText\Contracts\DictionaryRepositoryInterface;
use VergilLai\SensitiveText\Dictionary\SensitiveDictionary;
use VergilLai\SensitiveText\Exception\RedisUnavailableException;

final class FakeRepository implements DictionaryRepositoryInterface
{
    public bool $fail = false;

    public int $versionCalls = 0;

    public int $loadCalls = 0;

    public ?Closure $onLoad = null;

    public function __construct(public SensitiveDictionary $snapshot) {}

    public function version(): string
    {
        ++$this->versionCalls;
        $this->throwIfFailed();

        return $this->snapshot->version;
    }

    public function load(): SensitiveDictionary
    {
        ++$this->loadCalls;
        $this->throwIfFailed();
        if (null !== $this->onLoad) {
            ($this->onLoad)();
        }

        return $this->snapshot;
    }

    private function throwIfFailed(): void
    {
        if ($this->fail) {
            throw new RedisUnavailableException('Injected Redis failure.');
        }
    }
}
