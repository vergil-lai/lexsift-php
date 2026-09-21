<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Runtime;

use VergilLai\SensitiveText\Contracts\BatchExecutorInterface;
use VergilLai\SensitiveText\SensitiveText;

final readonly class SyncBatchExecutor implements BatchExecutorInterface
{
    public function __construct(private SensitiveText $scanner) {}

    public function scan(iterable $texts): iterable
    {
        foreach ($texts as $key => $text) {
            yield $key => $this->scanner->scan($text);
        }
    }
}
