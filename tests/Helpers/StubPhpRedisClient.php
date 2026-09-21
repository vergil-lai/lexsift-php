<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Tests\Helpers;

use Redis;
use Throwable;

final class StubPhpRedisClient extends Redis
{
    /** @var list<array{host: string, port: int, timeout: float, persistentId: ?string, retryInterval: int, readTimeout: float, context: ?array<mixed>}> */
    public array $connectCalls = [];

    /** @var list<mixed> */
    public array $authCalls = [];

    /** @var list<int> */
    public array $selectCalls = [];

    public bool $connectResult = true;

    public bool $authResult = true;

    public bool $selectResult = true;

    /** @var list<mixed> */
    public array $getResults = [];

    /** @var list<mixed> */
    public array $evalResults = [];

    public ?Throwable $getException = null;

    public ?string $commandError = null;

    public int $clearLastErrorCalls = 0;

    public int $getLastErrorCalls = 0;

    /** @var list<string> */
    public array $getCalls = [];

    /** @var list<array{script: string, args: array<mixed>, numKeys: int}> */
    public array $evalCalls = [];

    private ?string $lastError = null;

    /** @param array<mixed>|null $context */
    public function connect(
        string $host,
        int $port = 6379,
        float $timeout = 0,
        ?string $persistent_id = null,
        int $retry_interval = 0,
        float $read_timeout = 0,
        ?array $context = null,
    ): bool {
        $this->connectCalls[] = [
            'host' => $host,
            'port' => $port,
            'timeout' => $timeout,
            'persistentId' => $persistent_id,
            'retryInterval' => $retry_interval,
            'readTimeout' => $read_timeout,
            'context' => $context,
        ];

        return $this->connectResult;
    }

    public function auth(mixed $credentials): bool
    {
        $this->authCalls[] = $credentials;

        return $this->authResult;
    }

    public function select(int $db): bool
    {
        $this->selectCalls[] = $db;

        return $this->selectResult;
    }

    public function get(string $key): mixed
    {
        $this->getCalls[] = $key;
        if (null !== $this->getException) {
            throw $this->getException;
        }

        $this->lastError = $this->commandError;

        return array_shift($this->getResults);
    }

    /** @param array<mixed> $args */
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        $this->evalCalls[] = ['script' => $script, 'args' => $args, 'numKeys' => $num_keys];
        $this->lastError = $this->commandError;

        return array_shift($this->evalResults);
    }

    public function clearLastError(): bool
    {
        ++$this->clearLastErrorCalls;
        $this->lastError = null;

        return true;
    }

    public function getLastError(): ?string
    {
        ++$this->getLastErrorCalls;

        return $this->lastError;
    }
}
