#!/usr/bin/env bash

set -euo pipefail

repository_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
temporary_root=$(mktemp -d "${TMPDIR:-/tmp}/lexsift-php-release.XXXXXX")

cleanup() {
    rm -rf "$temporary_root"
}
trap cleanup EXIT

release_dir="$temporary_root/release"
package_dir="$temporary_root/package"
consumer_dir="$temporary_root/consumer"
mkdir -p "$release_dir" "$package_dir" "$consumer_dir"

composer archive --working-dir="$repository_root" --format=zip --dir="$release_dir" --no-interaction
archive=$(find "$release_dir" -maxdepth 1 -type f -name '*.zip' -print -quit)
if [[ -z "$archive" ]]; then
    echo 'Composer archive was not created.' >&2
    exit 1
fi
unzip -q "$archive" -d "$package_dir"

for required_path in README.md LICENSE CHANGELOG.md composer.json \
    src/Matcher.php examples/README.md examples/scan.php examples/batch-scan.php; do
    if [[ ! -f "$package_dir/$required_path" ]]; then
        echo "Release archive is missing $required_path." >&2
        exit 1
    fi
done

for excluded_path in src/Laravel config tests benchmarks .github .serena docs/superpowers .php-cs-fixer.php phpstan.neon.dist phpunit.xml.dist; do
    if [[ -e "$package_dir/$excluded_path" ]]; then
        echo "Release archive unexpectedly contains $excluded_path." >&2
        exit 1
    fi
done

cat > "$consumer_dir/composer.json" <<JSON
{
    "name": "lexsift-php/release-consumer",
    "repositories": [
        {"type": "path", "url": "$package_dir", "options": {"symlink": false}}
    ],
    "require": {"vergil-lai/lexsift-php": "@dev"},
    "minimum-stability": "dev",
    "prefer-stable": true
}
JSON
composer update --working-dir="$consumer_dir" --no-dev --prefer-dist --no-interaction

package_json=$(composer show --working-dir="$consumer_dir" vergil-lai/lexsift-php --format=json)
printf '%s\n' "$package_json" | php -r '
$package = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (array_intersect(["ext-redis", "illuminate/support", "laravel/framework"], array_keys($package["requires"] ?? []))) {
    fwrite(STDERR, "Installed package must not require Redis or Laravel.\n");
    exit(1);
}
'

cat > "$consumer_dir/scan.php" <<'PHP'
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use VergilLai\LexSift\Matcher;

$filter = new Matcher(
    terms: ['微信', '赌博'],
    whitelist: ['反赌博宣传'],
);
$matches = $filter->scan('反赌博宣传，请加我微❤️信');
if (count($matches) !== 1 || $matches[0]['term'] !== '微信' || $matches[0]['text'] !== '微❤️信') {
    throw new RuntimeException('External consumer returned an unexpected match.');
}
if ($filter->mask('请加我微❤️信') !== '请加我*') {
    throw new RuntimeException('External consumer returned an unexpected mask.');
}
echo "External consumer succeeded.\n";
PHP
php "$consumer_dir/scan.php"


echo "Release archive smoke succeeded."
