<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Runtime;

enum RuntimeEnvironment: string
{
    case Cli = 'cli';
    case Fpm = 'fpm';
    case Swoole = 'swoole';
    case OpenSwoole = 'openswoole';
    case RoadRunner = 'roadrunner';
    case FrankenPhp = 'frankenphp';
    case Unknown = 'unknown';

    /**
     * Runtime signals must describe the active process. An installed extension alone is not a signal.
     *
     * @param array<string, bool> $signals
     */
    public static function detect(?string $sapi = null, array $signals = []): self
    {
        $detected = [
            'frankenphp' => defined('FRANKENPHP'),
            'roadrunner' => false !== getenv('RR_MODE') && '' !== getenv('RR_MODE'),
            'openswoole' => self::hasActiveCoroutine('OpenSwoole\\Coroutine'),
            'swoole' => self::hasActiveCoroutine('Swoole\\Coroutine'),
        ];
        foreach ($signals as $name => $active) {
            $detected[$name] = $active;
        }

        foreach ([
            'frankenphp' => self::FrankenPhp,
            'roadrunner' => self::RoadRunner,
            'openswoole' => self::OpenSwoole,
            'swoole' => self::Swoole,
        ] as $signal => $environment) {
            if ($detected[$signal] ?? false) {
                return $environment;
            }
        }

        return match ($sapi ?? PHP_SAPI) {
            'fpm-fcgi' => self::Fpm,
            'cli', 'cli-server' => self::Cli,
            default => self::Unknown,
        };
    }

    private static function hasActiveCoroutine(string $coroutineClass): bool
    {
        if (!class_exists($coroutineClass) || !method_exists($coroutineClass, 'getCid')) {
            return false;
        }

        try {
            return $coroutineClass::getCid() >= 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
