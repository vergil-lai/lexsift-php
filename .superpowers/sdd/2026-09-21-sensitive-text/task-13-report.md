# Task 13 implementation report

## Status

DONE_WITH_CONCERNS: 文档、CI、发布包 smoke 与真实 PHP 8.2 最低依赖矩阵已完成并通过本地验证。本机没有 PCOV/Xdebug，因此 85% 覆盖率门槛只配置在 CI，未在本机取得覆盖率结果；PHP 8.3/8.4 与真实 Octane 宿主仍待 CI/目标环境验证。

## Implemented

- 新增 README，按 brief 的固定顺序覆盖安装、可连续执行的 phpredis Quick Start、架构、Redis 原子词库、归一化/AC/Regex/白名单/遮罩、reload、Laravel/Octane、批处理、性能、Worker 安全、错误和全部质量命令。
- 明确 `ext-redis` 为 Composer 强制依赖，不支持 Predis、client fallback 或 Redis Cluster；Laravel 连接必须使用 phpredis，并说明宿主连接 prefix 与包 prefix 不可重复。
- 新增 MIT `LICENSE`、`CHANGELOG.md`、`.gitattributes` 与发布清单；archive 保留 README/LICENSE/config/src，排除测试、benchmark、CI、内部计划和开发工具配置。
- 新增 PHP 8.2/8.3/8.4/8.5 CI 矩阵：8.2 使用 Pest 3 + Testbench 10，8.3–8.5 使用 Pest 4 + Testbench 11；所有通道安装并检查 intl/mbstring/redis，启动 Redis 7 并强制运行真实 Redis 集成测试。
- 新增独立 PCOV 覆盖率作业（85%）和发布包 smoke 作业；GitHub Actions 均固定到已核对的完整 commit SHA。
- 新增 `composer test:release` 与 `tests/Release/package-smoke.sh`。脚本实际 archive、解包并以 `--no-dev` 外部 consumer 安装，断言安装 metadata 的 `ext-redis === '*'`、无 Predis/任何 Illuminate/开发工具、平台 ext-redis success，并运行真实 Redis 扫描；另一 Laravel 13 consumer 验证自动发现和 Facade singleton。
- 最低依赖验证发现 `phpstan/phpstan:^2.0` 与当前代码不兼容，因此把开发工具下限收窄为已实测的 `^2.2.14`。同时固定新旧 PHP-CS-Fixer 对匿名类括号的同一规则，并将三个标量 Pest dataset 改为 Pest 3/4 均支持的参数元组。
- Redis 集成测试随机标识改用 PHP 8.2 `Random\\Randomizer::getBytes()`，消除 Pest 3 + PHPStan 对 `random_bytes()` 的错误 mixed 推断，测试语义不变。

## TDD evidence

发布 smoke 的 RED：在 README、LICENSE 和 export 规则创建前执行 `bash tests/Release/package-smoke.sh`，预期失败为 `Release archive is missing README.md.`。

发布 smoke 的 GREEN：最终外部 consumer 安装、真实 Redis 扫描、Laravel discovery/Facade 全部成功，并输出：

```text
Installed package set excludes Predis, Illuminate, and development tools.
ext-redis 6.3.0 success
External Redis consumer matched: 微❤️信
Laravel discovery and facade resolution succeeded.
Release package smoke passed.
```

最低依赖矩阵的 RED 依次暴露 PHP-CS-Fixer 3.50 匿名类规则差异、PHPStan 2.0/2.2.0 旧分析误报，以及 Pest 3 dataset 类型差异；没有通过 baseline、ignore、排除测试目录或伪造 Composer platform 消除失败。修正声明/测试兼容性后，真实 PHP 8.2.30 最低矩阵 GREEN。

## Verification

```text
SENSITIVE_TEXT_REDIS_TESTS=1 composer check
PHP-CS-Fixer 3.95.26: 0/74 files fixable
PHPStan 2.2.14 max: No errors
Pest 4.7.8 / Testbench 11.2.0 / Laravel 13.32.0: 209 passed (727 assertions)
真实 Redis integration: 5 passed
```

在 `/tmp` 独立副本中使用真实 PHP 8.2.30、真实 ext-redis 6.0.1 和 `--prefer-lowest --prefer-stable`：

```text
Pest 3.8.5 / Testbench 10.0.0 / Laravel 12.61.1 / PHPStan 2.2.14
composer validate --strict: pass
composer check-platform-reqs: pass, ext-redis success
composer check: pass
Pest: 209 passed (727 assertions), including 5 real Redis tests
```

其他验收：

- `composer validate --strict`：pass；本地忽略的 library lock 已刷新但按既有约定不提交。
- `composer check-platform-reqs`：pass，`ext-redis 6.3.0 success`。
- `bash tests/Release/package-smoke.sh`：pass。
- `php benchmarks/run.php`：12/12 组合通过，0 failed；原始忽略文件为 `benchmarks/results/20260922-025428-81569.jsonl`。
- `composer test:coverage`：未运行成功；本机明确报告 `No code coverage driver is available.`。CI coverage 作业使用 PCOV 并执行 `pest --coverage --min=85`。

## Self-review

- 独立审查最初指出 smoke 仅拒绝一个 Illuminate 包、README API/异常说明不完整、Actions 未固定 SHA、platform fallback 前缺少 help 探测；均已修正。
- 核对 README 的固定章节顺序与四句要求原文，公开 API/defaults、UTF-8 offset、emoji mask、非 semantic whitelist、V1 sync/poll/invalidate 边界均有说明。
- 核对 archive 内不含测试、benchmark、CI、内部计划和开发配置；生产 src/config 与 README/LICENSE 均保留。
- 核对 smoke 检查的是独立 consumer 的已安装 metadata/package names/platform 结果，不以源 `composer.json` 或 archive 成功替代安装验证。
- 核对 CI 没有 `--ignore-platform-req`、没有 Composer platform 假装 PHP 8.2，所有测试通道均启用真实 Redis gate。

## Files changed

- `.gitattributes`
- `.github/workflows/ci.yml`
- `.php-cs-fixer.php`
- `CHANGELOG.md`
- `LICENSE`
- `README.md`
- `composer.json`
- `docs/release-checklist.md`
- `tests/Release/package-smoke.sh`
- `tests/Integration/Redis/RedisDictionaryRepositoryTest.php`
- `tests/Unit/Redis/ClientAdapterTest.php`
- `tests/Unit/Redis/RedisDictionaryRepositoryTest.php`

## Concerns

- 本机无覆盖率驱动，因此没有本地 85% 数字；该门槛需由新增 CI PCOV 作业给出最终证据。
- PHP 8.2 与 8.5 已真实运行；PHP 8.3/8.4 矩阵仅完成工作流配置，尚未由远端 CI 执行。
- Worker 测试与文档只支持“按常驻进程安全设计”的结论；Swoole、RoadRunner、FrankenPHP 真机未验证。
- 未发布、未创建 tag、未 push、未注册 Packagist。
