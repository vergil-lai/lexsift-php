<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Tests\Integration\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use VergilLai\SensitiveText\Dictionary\DictionaryJsonCodec;
use VergilLai\SensitiveText\Exception\InvalidConfigurationException;
use VergilLai\SensitiveText\Laravel\Facades\SensitiveText as SensitiveTextFacade;
use VergilLai\SensitiveText\Rules\Action;
use VergilLai\SensitiveText\Rules\Severity;
use VergilLai\SensitiveText\SensitiveText;
use VergilLai\SensitiveText\Tests\Helpers\StubLaravelRedisConnection;
use VergilLai\SensitiveText\Tests\Helpers\StubLaravelRedisManager;

final class ProviderTest extends TestCase
{
    public function test_it_registers_a_lazy_singleton_independently_of_the_static_entry(): void
    {
        $app = $this->application();
        $first = $app->make(SensitiveText::class);
        $second = $app->make(SensitiveText::class);

        self::assertSame($first, $second);
        self::assertNotSame(SensitiveText::instance(), $first);
        self::assertNull($first->stats()->dictionaryVersion);
    }

    public function test_it_merges_publishable_scalar_only_package_configuration(): void
    {
        $config = $this->application()->make(ConfigRepository::class)->get('sensitive-text');

        self::assertSame([
            'redis' => ['connection' => 'default', 'prefix' => 'sensitive_text:'],
            'dictionary' => ['key' => 'dictionary', 'version_key' => 'dictionary:version'],
            'normalizer' => [
                'unicode_nfkc' => true,
                'lowercase' => true,
                'remove_whitespace' => true,
                'remove_punctuation' => false,
                'remove_symbols' => false,
                'remove_emoji' => true,
                'remove_characters' => [],
            ],
            'version_check_interval' => 5.0,
            'mask_character' => '*',
            'regex_rules' => [],
            'whitelist' => ['rules' => []],
            'reload' => ['policy' => 'keep_last_good'],
            'batch' => ['driver' => 'sync'],
        ], $config);
    }

    public function test_it_publishes_its_configuration_with_the_package_tag(): void
    {
        $destination = config_path('sensitive-text.php');
        if (is_file($destination)) {
            unlink($destination);
        }

        try {
            $this->runArtisan('vendor:publish', [
                '--tag' => 'sensitive-text-config',
                '--force' => true,
            ]);

            self::assertFileExists($destination);
            self::assertSame(
                $this->configurationFile(dirname(__DIR__, 3) . '/config/sensitive-text.php'),
                $this->configurationFile($destination),
            );
        } finally {
            if (is_file($destination)) {
                unlink($destination);
            }
        }
    }

    public function test_it_rebuilds_rule_dtos_from_cached_scalar_configuration(): void
    {
        $app = $this->application();
        $destination = config_path('sensitive-text.php');
        if (is_file($destination)) {
            unlink($destination);
        }
        $this->runArtisan('vendor:publish', [
            '--tag' => 'sensitive-text-config',
            '--force' => true,
        ]);
        $published = $this->configurationFile($destination);
        $published['regex_rules'] = [[
            'id' => 'allowed-contact',
            'pattern' => '/合法微信/u',
            'severity' => Severity::Critical->value,
            'action' => Action::Block->value,
            'target' => 'original',
        ]];
        $published['whitelist'] = ['rules' => [[
            'text' => '合法微信',
            'mode' => 'exact',
        ]]];
        file_put_contents($destination, '<?php return ' . var_export($published, true) . ';');

        try {
            $this->runArtisan('config:cache');
            $cached = $this->configurationFile($app->getCachedConfigPath());
            $cachedConfig = $this->associativeArray($cached['sensitive-text'] ?? null, 'cached sensitive-text');
            self::assertSame($published['regex_rules'], $cachedConfig['regex_rules'] ?? null);
            self::assertSame($published['whitelist'], $cachedConfig['whitelist'] ?? null);

            $app->make(ConfigRepository::class)->set('sensitive-text', $cachedConfig);
            $connection = new StubLaravelRedisConnection([[
                '1',
                (new DictionaryJsonCodec())->encode([]),
            ]]);
            $app->instance('redis', new StubLaravelRedisManager($app, $connection));
            $app->forgetInstance(SensitiveText::class);

            self::assertFalse($app->make(SensitiveText::class)->scan('合法微信')->matched());
        } finally {
            $this->runArtisan('config:clear');
            if (is_file($destination)) {
                unlink($destination);
            }
        }
    }

    public function test_it_rejects_unsupported_laravel_integration_options(): void
    {
        $app = $this->application();
        $repository = $app->make(ConfigRepository::class);
        $defaults = $this->associativeArray($repository->get('sensitive-text'), 'sensitive-text');
        foreach ([
            ['reload.policy', 'fail_closed'],
            ['batch.driver', 'async'],
            ['redis.driver', 'predis'],
        ] as [$path, $value]) {
            $repository->set('sensitive-text', $defaults);
            $repository->set("sensitive-text.{$path}", $value);
            $app->forgetInstance(SensitiveText::class);

            expect(fn() => $app->make(SensitiveText::class))
                ->toThrow(InvalidConfigurationException::class);
        }
    }

    public function test_it_maps_cached_scalar_rules_and_reuses_one_lazy_scanner_across_requests(): void
    {
        $app = $this->application();
        $repository = $app->make(ConfigRepository::class);
        $repository->set('sensitive-text.mask_character', '#');
        $repository->set('sensitive-text.regex_rules', [[
            'id' => 'wechat',
            'pattern' => '/微信/u',
            'category' => 'contact',
            'severity' => Severity::High->value,
            'action' => Action::Block->value,
            'target' => 'original',
            'metadata' => ['source' => 'config'],
        ]]);

        $payload = (new DictionaryJsonCodec())->encode([]);
        $connection = new StubLaravelRedisConnection([['1', $payload]]);
        $manager = new StubLaravelRedisManager($app, $connection);
        $app->instance('redis', $manager);
        $app->forgetInstance(SensitiveText::class);
        SensitiveTextFacade::clearResolvedInstance(SensitiveText::class);

        $scanner = $app->make(SensitiveText::class);
        self::assertSame(0, $manager->connectionCalls);
        self::assertNull($scanner->stats()->dictionaryVersion);

        $first = SensitiveTextFacade::scan('联系微信');
        $second = SensitiveTextFacade::scan('再次微信');

        self::assertSame($scanner, SensitiveTextFacade::getFacadeRoot());
        self::assertTrue($first->matched());
        self::assertSame('contact', $first->matches()[0]->category);
        self::assertSame(Severity::High, $first->matches()[0]->severity);
        self::assertSame(Action::Block, $first->recommendedAction());
        self::assertSame('联系##', $first->mask());
        self::assertTrue($second->matched());
        self::assertSame(1, $manager->connectionCalls);
        self::assertSame(['default'], $manager->connectionNames);
        self::assertCount(1, $connection->commands);
        self::assertSame('eval', $connection->commands[0]['method']);
    }

    /** @return array<array-key, mixed> */
    private function configurationFile(string $path): array
    {
        $value = require $path;
        if (!is_array($value)) {
            self::fail("Expected [{$path}] to return an array.");
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function associativeArray(mixed $value, string $path): array
    {
        if (!is_array($value)) {
            self::fail("Expected [{$path}] to return an array.");
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                self::fail("Expected [{$path}] to use string keys.");
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private function application(): Application
    {
        if (!$this->app instanceof Application) {
            self::fail('Testbench application is unavailable.');
        }

        return $this->app;
    }

    /** @param array<string, bool|string> $parameters */
    private function runArtisan(string $command, array $parameters = []): void
    {
        $result = $this->artisan($command, $parameters);
        if (is_int($result)) {
            self::assertSame(0, $result);

            return;
        }

        $result->assertExitCode(0);
    }
}
