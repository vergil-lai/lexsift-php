#!/usr/bin/env bash

set -euo pipefail

repository_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
temporary_root=$(mktemp -d "${TMPDIR:-/tmp}/sensitive-text-release.XXXXXX")

cleanup() {
    rm -rf "$temporary_root"
}
trap cleanup EXIT

release_dir="$temporary_root/release"
package_dir="$temporary_root/package"
consumer_dir="$temporary_root/consumer"
laravel_dir="$temporary_root/laravel-consumer"
mkdir -p "$release_dir" "$package_dir" "$consumer_dir" "$laravel_dir/bootstrap/cache"

composer archive --working-dir="$repository_root" --format=zip --dir="$release_dir" --no-interaction
archive=$(find "$release_dir" -maxdepth 1 -type f -name '*.zip' -print -quit)
if [[ -z "$archive" ]]; then
    echo 'Composer archive was not created.' >&2
    exit 1
fi
unzip -q "$archive" -d "$package_dir"

for required_path in README.md LICENSE CHANGELOG.md composer.json config/sensitive-text.php src/SensitiveText.php; do
    if [[ ! -f "$package_dir/$required_path" ]]; then
        echo "Release archive is missing $required_path." >&2
        exit 1
    fi
done

for excluded_path in tests benchmarks .github docs/superpowers .php-cs-fixer.php phpstan.neon.dist phpunit.xml.dist; do
    if [[ -e "$package_dir/$excluded_path" ]]; then
        echo "Release archive unexpectedly contains $excluded_path." >&2
        exit 1
    fi
done

cat > "$consumer_dir/composer.json" <<JSON
{
    "name": "sensitive-text/release-consumer",
    "repositories": [
        {"type": "path", "url": "$package_dir", "options": {"symlink": false}}
    ],
    "require": {
        "vergil-lai/sensitive-text": "@dev"
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
JSON

composer update --working-dir="$consumer_dir" --no-dev --prefer-dist --no-interaction

package_json=$(composer show --working-dir="$consumer_dir" vergil-lai/sensitive-text --format=json)
printf '%s\n' "$package_json" | php -r '
$package = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($package["requires"]["ext-redis"] ?? null) !== "*") {
    fwrite(STDERR, "Installed package must require ext-redis: *\n");
    exit(1);
}
'

installed_json=$(composer show --working-dir="$consumer_dir" --format=json)
printf '%s\n' "$installed_json" | php -r '
$document = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$packages = $document["installed"] ?? null;
if (!is_array($packages)) {
    fwrite(STDERR, "Composer installed package list is missing.\n");
    exit(1);
}
$names = array_values(array_filter(array_map(
    static fn (array $package): ?string => $package["name"] ?? null,
    $packages,
)));
$forbidden = [
    "friendsofphp/php-cs-fixer",
    "orchestra/testbench",
    "pestphp/pest",
    "phpstan/phpstan",
    "predis/predis",
];
$unexpected = array_values(array_filter(
    $names,
    static fn (string $name): bool => str_starts_with($name, "illuminate/")
        || in_array($name, $forbidden, true),
));
if ([] !== $unexpected) {
    fwrite(STDERR, "Unexpected installed packages: ".implode(", ", $unexpected)."\n");
    exit(1);
}
echo "Installed package set excludes Predis, Illuminate, and development tools.\n";
'

platform_help=$(composer check-platform-reqs --working-dir="$consumer_dir" --help)
if grep -Fq -- '--format=FORMAT' <<<"$platform_help"; then
    platform_json=$(composer check-platform-reqs --working-dir="$consumer_dir" --no-dev --format=json)
    printf '%s\n' "$platform_json"
    printf '%s\n' "$platform_json" | php -r '
$requirements = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$redis = array_values(array_filter(
    $requirements,
    static fn (array $requirement): bool => ($requirement["name"] ?? null) === "ext-redis",
));
if (count($redis) !== 1 || ($redis[0]["status"] ?? null) !== "success") {
    fwrite(STDERR, "ext-redis platform requirement did not succeed.\n");
    exit(1);
}
'
else
    composer check-platform-reqs --working-dir="$consumer_dir" --no-dev
    redis_json=$(composer show --working-dir="$consumer_dir" ext-redis --format=json)
    printf '%s\n' "$redis_json" | php -r '
$redis = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($redis["name"] ?? null) !== "ext-redis" || [] === ($redis["versions"] ?? [])) {
    fwrite(STDERR, "ext-redis platform package is unavailable.\n");
    exit(1);
}
'
fi

cat > "$consumer_dir/scan.php" <<'PHP'
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use VergilLai\SensitiveText\Dictionary\RedisDictionaryRepository;
use VergilLai\SensitiveText\Dictionary\SensitiveTerm;
use VergilLai\SensitiveText\Redis\PhpRedisClientAdapter;
use VergilLai\SensitiveText\Rules\Action;
use VergilLai\SensitiveText\Rules\Severity;
use VergilLai\SensitiveText\SensitiveText;
use VergilLai\SensitiveText\SensitiveTextConfig;

$host = getenv('SENSITIVE_TEXT_REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('SENSITIVE_TEXT_REDIS_PORT') ?: 6379);
$prefix = 'sensitive_text:release:'.bin2hex(random_bytes(8)).':';
$redis = new Redis();
$redis->connect($host, $port, 1.0);

try {
    $repository = new RedisDictionaryRepository(new PhpRedisClientAdapter($redis), $prefix);
    $repository->publish([
        new SensitiveTerm('微信', 'contact', Severity::Medium, Action::Review),
    ], null);
    $scanner = SensitiveText::fromConfig(new SensitiveTextConfig(
        redisUrl: "tcp://{$host}:{$port}",
        redisPrefix: $prefix,
    ));
    $result = $scanner->scan('请加我微❤️信联系');
    if (!$result->matched() || '微❤️信' !== $result->matches()[0]->matchedText) {
        throw new RuntimeException('External consumer did not scan the published Redis dictionary.');
    }
    echo "External Redis consumer matched: {$result->matches()[0]->matchedText}\n";
} finally {
    $redis->del([$prefix.'dictionary', $prefix.'dictionary:version']);
    $redis->close();
}
PHP
php "$consumer_dir/scan.php"

cat > "$laravel_dir/composer.json" <<JSON
{
    "name": "sensitive-text/laravel-consumer",
    "repositories": [
        {"type": "path", "url": "$package_dir", "options": {"symlink": false}}
    ],
    "require": {
        "laravel/framework": "^13.0",
        "vergil-lai/sensitive-text": "@dev"
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
JSON
composer update --working-dir="$laravel_dir" --no-dev --prefer-dist --no-interaction

cat > "$laravel_dir/discovery.php" <<'PHP'
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Redis\RedisServiceProvider;
use Illuminate\Support\Facades\Facade;
use VergilLai\SensitiveText\Laravel\Facades\SensitiveText as SensitiveTextFacade;
use VergilLai\SensitiveText\Laravel\SensitiveTextServiceProvider;
use VergilLai\SensitiveText\SensitiveText;

$app = new Application(__DIR__);
$app->instance('config', new Repository([
    'app' => ['providers' => []],
    'database' => ['redis' => [
        'client' => 'phpredis',
        'default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0],
    ]],
]));
$app->register(RedisServiceProvider::class);

$manifest = new PackageManifest(new Filesystem(), __DIR__, __DIR__.'/bootstrap/cache/packages.php');
$providers = $manifest->providers();
if (!in_array(SensitiveTextServiceProvider::class, $providers, true)) {
    throw new RuntimeException('Laravel package discovery did not expose the service provider.');
}
foreach ($providers as $provider) {
    if (SensitiveTextServiceProvider::class === $provider) {
        $app->register($provider);
    }
}

Facade::setFacadeApplication($app);
$root = SensitiveTextFacade::getFacadeRoot();
if (!$root instanceof SensitiveText || $root !== $app->make(SensitiveText::class)) {
    throw new RuntimeException('Laravel facade did not resolve the discovered singleton.');
}
echo "Laravel discovery and facade resolution succeeded.\n";
PHP
php "$laravel_dir/discovery.php"

echo 'Release package smoke passed.'
