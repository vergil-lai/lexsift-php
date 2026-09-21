<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Tests\Helpers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\RedisManager;
use Redis;

final class StubLaravelRedisConnection extends PhpRedisConnection
{
    /** @var list<array{method: string, parameters: array<array-key, mixed>}> */
    public array $commands = [];

    /** @param list<mixed> $results */
    public function __construct(private array $results = [])
    {
        parent::__construct(new Redis());
    }

    /** @param array<array-key, mixed> $parameters */
    public function command($method, array $parameters = [])
    {
        $this->commands[] = ['method' => $method, 'parameters' => $parameters];
        $result = array_shift($this->results);
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }
}

final class StubLaravelRedisManager extends RedisManager
{
    public int $connectionCalls = 0;

    /** @var list<mixed> */
    public array $connectionNames = [];

    public function __construct(Application $app, private readonly PhpRedisConnection $connection)
    {
        parent::__construct($app, 'phpredis', []);
    }

    public function connection($name = null)
    {
        ++$this->connectionCalls;
        $this->connectionNames[] = $name;

        return $this->connection;
    }
}
