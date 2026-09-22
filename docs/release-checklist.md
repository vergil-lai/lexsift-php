# Release checklist

Use one status for every check and retain its command output in the release task:

- `[x] PASS` — run completed successfully;
- `[ ] NOT RUN` — not executed, with the missing environment or authorization recorded;
- `[!] FAILED` — executed and failed, with the failure recorded.

A local archive smoke is not proof that Packagist installation works. Do not mark publishing complete until the tagged package is installed from Packagist in a clean consumer.

## Package identity and contents

- `[ ] NOT RUN` Confirm package name `vergil-lai/sensitive-text`, namespace `VergilLai\SensitiveText\`, MIT license, README examples, and changelog.
- `[ ] NOT RUN` Run `composer validate --strict`.
- `[ ] NOT RUN` Run `composer check-platform-reqs`; confirm `ext-intl`, `ext-mbstring`, and `ext-redis` succeed on the real platform.
- `[ ] NOT RUN` Run `composer test:release`; retain the installed package metadata, forbidden-package result, and `ext-redis ... success` output.
- `[ ] NOT RUN` Inspect the archive: keep `README.md`, `LICENSE`, `CHANGELOG.md`, `composer.json`, `config`, `src`, and user documentation; exclude tests, benchmarks, CI, planning files, lockfile, and development tool configuration.

## Quality and compatibility

- `[ ] NOT RUN` Run `composer check`.
- `[ ] NOT RUN` Run `SENSITIVE_TEXT_REDIS_TESTS=1 composer test` against a dedicated real Redis server.
- `[ ] NOT RUN` Run `composer test:coverage` with PCOV/Xdebug and confirm at least 85% line coverage.
- `[ ] NOT RUN` Run `php benchmarks/run.php`; compare like-for-like host, PHP, ICU, iterations, and data shapes.
- `[ ] NOT RUN` Confirm CI passed on real PHP 8.2, 8.3, 8.4, and 8.5, including the PHP 8.2 lowest-dependency job.
- `[ ] NOT RUN` Confirm Laravel 12/Testbench 10 and Laravel 13/Testbench 11 solver/test channels passed.
- `[ ] NOT RUN` Record Swoole/OpenSwoole, RoadRunner, FrankenPHP, and Octane real-host results separately; simulated worker tests do not certify them.

## phpredis-only boundary

- `[ ] NOT RUN` Confirm installed metadata requires `ext-redis: *`.
- `[ ] NOT RUN` Confirm the no-dev consumer contains no `predis/predis`, Illuminate packages, Pest, PHPStan, or PHP-CS-Fixer.
- `[ ] NOT RUN` Confirm the Laravel consumer discovers the provider and resolves the facade while configured for phpredis.
- `[ ] NOT RUN` Confirm documentation has no fallback, driver-selection, or Redis Cluster support claim.

## Publication

- `[ ] NOT RUN` Review the final diff and create the intended release commit.
- `[ ] NOT RUN` Select the version and update the changelog only after approval.
- `[ ] NOT RUN` Create and push the version tag only after approval.
- `[ ] NOT RUN` Publish/register on Packagist only after approval.
- `[ ] NOT RUN` Install the tagged version from Packagist into clean plain-PHP and Laravel consumers.
