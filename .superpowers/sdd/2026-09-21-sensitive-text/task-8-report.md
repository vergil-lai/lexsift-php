# Task 8 实施报告：PhpRedis 适配与真实 Redis 测试

## 状态

完成。新增仅支持 `ext-redis` 的 `PhpRedisClientAdapter`，收窄 `GET`/Lua 的 `mixed` 返回值，固定执行 Task 7 的原子快照和 CAS 脚本，并将连接、命令与脚本错误包装为 `RedisUnavailableException`。没有新增 Predis、运行时 fallback、driver selection 或 Redis Cluster 支持。

本机 PHP 已加载 phpredis 6.3.0；Redis 7.4.1 位于 `127.0.0.1:6379`，沙箱外 `connect()` 与 `PING` 成功，因此真实集成测试已启用并实跑。

## TDD 证据

### RED

1. `rtk vendor/bin/pest tests/Unit/Redis/ClientAdapterTest.php`
   - 结果：1 failed；`PhpRedisClientAdapter` 类不存在。
2. `rtk vendor/bin/pest tests/Unit/Redis/ClientAdapterTest.php --filter='snapshot'`
   - 结果：1 failed；`readSnapshot()` 尚未实现。
3. `rtk vendor/bin/pest tests/Unit/Redis/ClientAdapterTest.php --filter='compare-and-swap'`
   - 结果：1 failed；`compareAndSwap()` 尚未实现。
4. `rtk vendor/bin/pest tests/Unit/Redis/ClientAdapterTest.php --filter='associative snapshot'`
   - 结果：1 failed；两个关联元素被错误当成合法 snapshot pair。
5. `SENSITIVE_TEXT_REDIS_TESTS=1 rtk vendor/bin/pest tests/Integration/Redis`
   - 结果：2 failed、3 passed。主动 `close()` 后 phpredis 把缺连接的 `GET` 表现成无 error state 的 `false`；Redis 7.4 的 `ACL SETUSER` 经 phpredis 返回 `true`，不是字符串 `OK`。

### GREEN

1. `rtk vendor/bin/pest tests/Unit/Redis/ClientAdapterTest.php`
   - 结果：17 passed（37 assertions）。单测使用继承 `Redis` 的可控 stub，不建立网络连接。
2. `SENSITIVE_TEXT_REDIS_TESTS=1 rtk vendor/bin/pest tests/Integration/Redis`
   - 结果：5 passed（17 assertions）。覆盖原子 snapshot、自定义 prefix、缺 key、合法空库、双连接 CAS、socket 中断与真实 ACL/NOPERM 脚本错误。
3. `rtk composer check`
   - 结果：PHP-CS-Fixer 0 files、PHPStan no errors、141 passed（273 assertions）、5 个受环境开关控制的集成测试 skipped。

## 文件

- `src/Redis/PhpRedisClientAdapter.php`
- `tests/Helpers/StubPhpRedisClient.php`
- `tests/Unit/Redis/ClientAdapterTest.php`
- `tests/Integration/Redis/RedisDictionaryRepositoryTest.php`

## 实现与自审

- 每次 phpredis 命令前调用 `clearLastError()`，命令后读取 `getLastError()`；因此 `GET false/null` 只在没有 Redis error 时表示缺 key，CAS `false/null` 只在没有 Redis error 时表示冲突。
- phpredis 抛出的异常包装为 `RedisUnavailableException` 并保留 `previous`；返回 error state 时抛出带 Redis 错误消息的同类异常。
- snapshot 只接受恰好两个元素的 list；元素仅接受 string、false 或 null。CAS 成功值只接受数字字符串。
- Lua keys 位于 packed argument array 前两个位置，`num_keys=2`；null expected version 编码为空字符串。脚本与 Task 7 协议逐字一致，写入前完成校验，最后只执行一次 `MSET`。
- socket 测试关闭 phpredis 自动重试后，由第二连接仅 `CLIENT KILL ID` 当前测试连接，证明 `Connection lost` 被包装且保留 `RedisException`。
- ACL 测试只创建随机临时用户，禁用 `EVAL` 后验证真实 `NOPERM`；`finally` 删除该用户。所有数据测试只删除随机 prefix 的 dictionary/version 两个 key；未使用 `FLUSHDB`/`FLUSHALL`。
- `git diff --check` 与四个新增 PHP 文件 `php -l` 均通过；范围搜索确认没有 Predis、RedisCluster、fallback、driver selection 或 `extension_loaded()` 分支。

## 疑虑与限制

- PHP-CS-Fixer 与测试运行于 PHP 8.5.4，项目最低版本是 PHP 8.2；实现已避免 PHP 8.3 typed class constant，但本轮没有独立 PHP 8.2 运行环境。
- 普通 `composer check` 按 brief 保留 server gate，因此会跳过 5 个真实 Redis 测试；CI 后续必须设置 `SENSITIVE_TEXT_REDIS_TESTS=1` 并提供 Redis/ext-redis。
- ACL 集成需要测试 Redis 允许当前用户执行 `ACL SETUSER`/`ACL DELUSER`；本机已真实验证，未修改 default 用户或 Redis 全局配置。
