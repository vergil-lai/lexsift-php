# 发布检查清单

## 包内容

- `[ ]` 确认包名、命名空间、MIT 许可证、README 和 CHANGELOG。
- `[ ]` 执行 `composer validate --strict`。
- `[ ]` 执行 `composer check-platform-reqs`，确认 `ext-intl` 与 `ext-mbstring`。
- `[ ]` 执行 `composer test:release`，验证归档中的独立 PHP 使用流程。
- `[ ]` 确认归档包含 `README.md`、`LICENSE`、`CHANGELOG.md`、`composer.json`、`src` 和 `examples`。
- `[ ]` 确认归档排除测试、基准、CI、计划、锁文件、开发工具配置和 `.serena`。

## 质量与兼容性

- `[ ]` 执行 `composer check`。
- `[ ]` 执行 `composer test:coverage` 并确认覆盖率至少为 85%。
- `[ ]` 执行 `php benchmarks/run.php`，与相同环境的基线比较。
- `[ ]` 确认 PHP 8.2、8.3、8.4、8.5 和最低依赖 CI 任务通过。

## 发布

- `[ ]` 审查最终差异并创建发布提交。
- `[ ]` 获得版本确认后更新 CHANGELOG、创建并推送标签。
- `[ ]` 从 Packagist 安装已发布版本到全新的独立 PHP 项目验证。
