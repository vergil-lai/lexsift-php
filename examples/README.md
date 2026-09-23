# 示例

在项目根目录执行 `composer install` 后运行：

```bash
php examples/scan.php '请加我微❤️信，远离赌博。'
php examples/batch-scan.php
```

示例使用 `VergilLai\LexSift\Matcher`。扫描返回数组和 UTF-8 字节偏移，批量处理复用同一实例。
