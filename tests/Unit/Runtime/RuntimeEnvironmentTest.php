<?php

declare(strict_types=1);

use VergilLai\SensitiveText\Runtime\RuntimeEnvironment;

it('prefers reliable runtime signals over the sapi', function () {
    expect(RuntimeEnvironment::detect('cli', ['roadrunner' => true]))
        ->toBe(RuntimeEnvironment::RoadRunner);
});

it('uses a fixed priority when multiple runtime signals are present', function () {
    expect(RuntimeEnvironment::detect('cli', [
        'frankenphp' => true,
        'roadrunner' => true,
        'openswoole' => true,
        'swoole' => true,
    ]))->toBe(RuntimeEnvironment::FrankenPhp)
        ->and(RuntimeEnvironment::detect('cli', [
            'roadrunner' => true,
            'openswoole' => true,
            'swoole' => true,
        ]))->toBe(RuntimeEnvironment::RoadRunner)
        ->and(RuntimeEnvironment::detect('cli', [
            'openswoole' => true,
            'swoole' => true,
        ]))->toBe(RuntimeEnvironment::OpenSwoole)
        ->and(RuntimeEnvironment::detect('cli', ['swoole' => true]))
        ->toBe(RuntimeEnvironment::Swoole);
});

it('falls back to recognized sapi values', function (string $sapi, string $expected) {
    expect(RuntimeEnvironment::detect($sapi, [
        'frankenphp' => false,
        'roadrunner' => false,
        'openswoole' => false,
        'swoole' => false,
    ]))->toBe(RuntimeEnvironment::from($expected));
})->with([
    'fpm' => ['fpm-fcgi', 'fpm'],
    'cli' => ['cli', 'cli'],
    'unknown' => ['apache2handler', 'unknown'],
]);

it('does not treat an installed extension as an active runtime', function () {
    expect(RuntimeEnvironment::detect('apache2handler', [
        'frankenphp' => false,
        'roadrunner' => false,
        'openswoole' => false,
        'swoole' => false,
    ]))->toBe(RuntimeEnvironment::Unknown);
});
