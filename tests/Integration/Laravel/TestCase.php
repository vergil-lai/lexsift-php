<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Tests\Integration\Laravel;

use Orchestra\Testbench\TestCase as Orchestra;
use VergilLai\SensitiveText\Laravel\SensitiveTextServiceProvider;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [SensitiveTextServiceProvider::class];
    }
}
