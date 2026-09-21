<?php

declare(strict_types=1);

use VergilLai\SensitiveText\Dictionary\SensitiveTerm;
use VergilLai\SensitiveText\Rules\Action;
use VergilLai\SensitiveText\Rules\Severity;

it('keeps immutable term metadata and action order', function () {
    $term = new SensitiveTerm('赌博', 'gambling', Severity::High, Action::Block);

    expect($term->term)->toBe('赌博')
        ->and($term->category)->toBe('gambling')
        ->and($term->severity)->toBe(Severity::High)
        ->and($term->action)->toBe(Action::Block)
        ->and($term->enabled)->toBeTrue()
        ->and($term->metadata)->toBe([])
        ->and(Action::Block->rank())->toBeGreaterThan(Action::Review->rank());
    $property = new ReflectionProperty($term, 'term');
    expect(fn() => $property->setValue($term, 'changed'))->toThrow(Error::class);
});

it('defines severity values and every action rank', function () {
    expect(Severity::Low->value)->toBe(1)
        ->and(Severity::Medium->value)->toBe(2)
        ->and(Severity::High->value)->toBe(3)
        ->and(Severity::Critical->value)->toBe(4)
        ->and(Action::Allow->rank())->toBe(0)
        ->and(Action::Flag->rank())->toBe(1)
        ->and(Action::Review->rank())->toBe(2)
        ->and(Action::Block->rank())->toBe(3);
});

it('rejects an empty category', function () {
    expect(fn() => new SensitiveTerm('term', ''))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts metadata with one nested array', function () {
    $metadata = [
        'source' => 'manual',
        'score' => 10,
        'options' => ['exact' => true, 'note' => null],
    ];

    expect((new SensitiveTerm('term', metadata: $metadata))->metadata)->toBe($metadata);
});

it('rejects unsupported metadata values', function (array $metadata) {
    $reflection = new ReflectionClass(SensitiveTerm::class);

    expect(fn() => $reflection->newInstanceArgs([
        'term',
        'default',
        Severity::Medium,
        Action::Flag,
        true,
        $metadata,
    ]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'object' => [['value' => new stdClass()]],
    'nested too deeply' => [['value' => [['nested' => true]]]],
]);

it('rejects resource metadata values', function () {
    $resource = fopen('php://memory', 'rb');
    if (false === $resource) {
        throw new RuntimeException('Unable to open the in-memory stream.');
    }

    try {
        $reflection = new ReflectionClass(SensitiveTerm::class);
        expect(fn() => $reflection->newInstanceArgs([
            'term',
            'default',
            Severity::Medium,
            Action::Flag,
            true,
            ['value' => $resource],
        ]))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        fclose($resource);
    }
});
