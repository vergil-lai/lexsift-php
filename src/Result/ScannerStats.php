<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Result;

use DateTimeImmutable;

final readonly class ScannerStats
{
    public function __construct(
        public ?string $dictionaryVersion,
        public int $termCount,
        public int $automatonNodeCount,
        public float $lastCompileDuration,
        public ?DateTimeImmutable $lastReloadAt,
        public int $estimatedMemoryBytes,
        public ?DateTimeImmutable $versionLastCheckedAt,
        public ?string $lastReloadError,
    ) {}
}
