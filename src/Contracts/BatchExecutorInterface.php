<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Contracts;

use VergilLai\SensitiveText\Result\ScanResult;

interface BatchExecutorInterface
{
    /**
     * @param iterable<array-key, string> $texts
     *
     * @return iterable<array-key, ScanResult>
     */
    public function scan(iterable $texts): iterable;
}
