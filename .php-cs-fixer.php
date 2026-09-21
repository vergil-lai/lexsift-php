<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/config']);

return (new PhpCsFixer\Config())->setRiskyAllowed(true)->setRules([
    '@PER-CS' => true,
    'declare_strict_types' => true,
    'ordered_imports' => true,
    'no_unused_imports' => true,
    'single_quote' => true,
    'array_syntax' => ['syntax' => 'short'],
])->setFinder($finder);
