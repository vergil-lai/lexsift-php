# Redis 词库协议

Redis 只保存词库原始记录，运行时归一化结果和自动机不进入协议。默认 key 为：

```text
sensitive_text:dictionary
sensitive_text:dictionary:version
```

构造 `RedisDictionaryRepository` 时可以分别配置 prefix、词库 key 后缀和版本 key 后缀。两个最终 key 必须位于同一个 Redis primary；V1 支持单 primary 或 Sentinel 选出的 primary，不支持 Redis Cluster 的跨槽 key。

## Payload

词库值是 UTF-8 JSON：

```json
{"schema":1,"terms":[{"term":"赌博","category":"gambling","severity":3,"action":"block","enabled":true,"metadata":{"source":"manual"}}]}
```

root 只允许 `schema` 和 `terms`。`schema` 必须是整数 `1`，`terms` 必须是 JSON array。每条记录只允许 `term`、`category`、`severity`、`action`、`enabled`、`metadata`；未知字段会被拒绝。`term` 和 `category` 是非空字符串，`severity` 是 `1..4`，`action` 是 `allow`、`flag`、`review` 或 `block`，`enabled` 是布尔值。`metadata` 的顶层 key 是字符串，值可以是 JSON scalar、null，或再嵌套一层仅含 scalar/null 的数组或对象。

合法空词库是 `{"schema":1,"terms":[]}`。缺少词库 key 或版本 key 表示快照不完整，不等同于空词库。payload 不保存 `normalizedTerm`；加载后必须按当前 normalizer 配置重新编译。

版本是没有前导零的非负十进制字符串，最多 19 位且不大于 `9223372036854775807`。实现不得把完整版本转为 PHP int 或 Lua number。首次从缺失版本发布得到 `1`。

## 原子读取

客户端必须把版本 key 作为 `KEYS[1]`、词库 key 作为 `KEYS[2]`，执行固定脚本：

```lua
return {redis.call('GET', KEYS[1]), redis.call('GET', KEYS[2])}
```

调用方必须使用脚本返回的同一对值，不能分别 `GET` 后拼接快照。

## 原子发布

发布使用 compare-and-swap。客户端把期望版本放入 `ARGV[1]`；PHP 的 `null` 编码为空字符串。新 payload 放入 `ARGV[2]`。版本 key 和词库 key 的顺序与读取脚本相同。

```lua
local current = redis.call('GET', KEYS[1])
if (current or '') ~= ARGV[1] then return false end
local value = current or '0'
if not string.match(value, '^%d+$') or (#value > 1 and string.sub(value, 1, 1) == '0') then
  return redis.error_reply('Invalid dictionary version')
end
if #value > 19 or (#value == 19 and value >= '9223372036854775807') then
  return redis.error_reply('Dictionary version overflow')
end
local digits = {}
local carry = 1
for i = #value, 1, -1 do
  local digit = tonumber(string.sub(value, i, i)) + carry
  if digit == 10 then digit = 0; carry = 1 else carry = 0 end
  digits[i] = tostring(digit)
end
local nextVersion = (carry == 1 and '1' or '') .. table.concat(digits)
redis.call('MSET', KEYS[1], nextVersion, KEYS[2], ARGV[2])
return nextVersion
```

脚本先完成 CAS、当前版本格式校验、溢出校验和字符串递增，最后以唯一一次 `MSET` 同时替换版本和 payload。不得改成 `INCR` 后 `SET`；Lua 返回错误不会回滚此前已经执行的写命令。CAS 返回 `false` 表示版本冲突，Redis error 必须作为错误上抛。Redis 禁用脚本时，后续 `PhpRedisClientAdapter` 必须明确报错，不能降级成非原子命令序列。

## 运行约束

- 两个 key 专用于本协议，版本只能通过上述 CAS 脚本变更。
- 不得给任一 key 单独设置 TTL；否则会产生半个快照。
- 发布期间不得存在绕过 CAS 的外部写者。
- 备份、迁移和人工恢复必须把两个 key 视为一个不可分割的快照。
