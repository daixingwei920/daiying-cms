# Daiying CMS 1.2.68 Final Release Report

生成时间：2026-09-22

## 最终结论

Daiying CMS 1.2.68 已完成 P1 Closeout Release 的最终发布链验证与生产升级。

最终状态：

```text
DAIYING_CMS_1_2_68_RELEASE_STATUS = PASS
DAIYING_CMS_1_2_68_FREEZE_STATUS = FROZEN
```

1.2.68 从现在起冻结。

后续新问题、新修复、新兼容性调整、新回归测试补充，全部进入 1.2.69 或后续版本，不再回头修改 1.2.68 的 Git tag、发布包或已签名 artifact。

## Source / Tag

Git tag：

```text
v1.2.68
```

Exact commit：

```text
e6efb2a5d93f74489f01fc1bf77058497e20f9b9
```

该 commit 已作为 1.2.68 的冻结源码基线。

## 正式签名包

正式 1.2.68 Core update package：

```text
daiying.cms:1.2.68:stable
```

正式包 SHA256：

```text
830b789ae58bed2c9540e16b2160f4b6f2f70b1839af693dc52fa93b2875ad5d
```

正式包版本信息：

```text
version = 1.2.68
release_id = daiying-cms-core-update-1.2.68
build = 20260922050356-core-update
channel = stable
min_upgrade_from = 1.2.67
hard_min_version = 1.2.67
migration_floor = 1.2.67
```

正式包包含并验证：

```text
public/index.php
cli.php
system/core-manifest.json
system/core/**
system/migrations/**
operational support files
```

## 兼容桥接包

1.2.68 正式包必须包含 `public/index.php`，用于关闭 Legacy Root Shell / Active Release 完整性技术债。

生产 1.2.67 updater 的旧 allowlist 不允许 `public/index.php`，直接验证 1.2.68 会失败：

```text
Update package may only target Core-owned paths.
```

因此本次采用两阶段 signed compatibility bridge：

```text
1.2.67
→ 1.2.67.1 compatibility bridge
→ 1.2.68
```

桥接包：

```text
version = 1.2.67.1
release_id = daiying-cms-compat-bridge-1.2.67.1
```

桥接包 SHA256：

```text
38dd12a2431681300bfef1acd1e160f92327a0151c6cc68ae1cb4bbae30691ef
```

桥接包约束：

- 不包含 `public/index.php`
- 不修改 root `public/index.php`
- 不执行数据库迁移
- migration_count = 0
- 只更新 active release 中的 updater allowlist
- 让下一次请求/CLI 通过 active release 代码接受正式 1.2.68 包

桥接目的：

```text
让 1.2.67 updater 合法获得 public/index.php allowlist 支持，
然后再安装冻结的正式 1.2.68 签名包。
```

## 生产升级记录

生产站点：

```text
https://daiyingcms.com
root = /www/wwwroot/saas.daiyinggame.com
```

生产升级前备份：

```text
/www/wwwroot/saas.daiyinggame.com/storage/backups/pre-1.2.68-bridge-20260922T055109Z/core-runtime-backup.tar.gz
```

备份 SHA256：

```text
72d8e724efeda08bbc388d9867c00915e93af89e65ab220173cdaee9d254f910
```

生产升级路径：

```text
1.2.67
→ 1.2.67.1 compatibility bridge
→ 1.2.68
```

正式 1.2.68 生产升级 operation_id：

```text
3cabb0786c62459faf33dcdf
```

最终生产 `/health`：

```json
{"status":"ok","mode":"NORMAL","version":"1.2.68","release_id":"daiying-cms-core-update-1.2.68","maintenance":false,"installed":true}
```

最终生产 Active Release：

```json
{
  "release_id": "daiying-cms-core-update-1.2.68",
  "version": "1.2.68",
  "build": "20260922050356-core-update",
  "path": "/www/wwwroot/saas.daiyinggame.com/storage/updates/releases/daiying-cms-core-update-1.2.68",
  "switched_at": "2026-09-22T05:53:10+00:00"
}
```

生产最终校验：

```text
/health = ok / NORMAL / 1.2.68
active release integrity = ok
root public/index.php matches official 1.2.68 package = true
root cli.php matches official 1.2.68 package = true
public/index.php allowlist = true
maintenance.mode absent
recovery.mode absent
core-update.lock absent
```

## Staging / daixingwei.cn 同步升级记录

截图中报错来自 staging 站点：

```text
https://www.daixingwei.cn
root = /www/wwwroot/www.daixingwei.cn_daiying_staging
```

该站当时仍在 active release `1.2.67`，所以后台直接验证 1.2.68 正式包时命中同一个旧 allowlist 问题。

staging 升级前备份：

```text
/www/wwwroot/www.daixingwei.cn_daiying_staging/storage/backups/pre-1.2.68-bridge-20260922T111044Z/core-runtime-backup.tar.gz
```

备份 SHA256：

```text
e674ea734af21a756f0e25461169ea6e114374219b7376c6dd535e34fc4de48f
```

staging 升级路径：

```text
1.2.67
→ 1.2.67.1 compatibility bridge
→ 1.2.68
```

正式 1.2.68 staging 升级 operation_id：

```text
0d50d9a587f2a1ab3e34abf7
```

最终 staging `/health`：

```json
{"status":"ok","mode":"NORMAL","version":"1.2.68","release_id":"daiying-cms-core-update-1.2.68","maintenance":false,"installed":true}
```

最终 staging Active Release：

```json
{
  "release_id": "daiying-cms-core-update-1.2.68",
  "version": "1.2.68",
  "build": "20260922050356-core-update",
  "path": "/www/wwwroot/www.daixingwei.cn_daiying_staging/storage/updates/releases/daiying-cms-core-update-1.2.68",
  "switched_at": "2026-09-22T11:12:11+00:00"
}
```

staging 最终校验：

```text
/health = ok / NORMAL / 1.2.68
active release integrity = ok
root public/index.php matches official 1.2.68 package = true
root cli.php matches official 1.2.68 package = true
public/index.php allowlist = true
maintenance.mode absent
recovery.mode absent
core-update.lock absent
```

## 已知执行注意事项

本次生产桥接阶段曾发现：如果用 `root` 用户直接执行 updater，`current-release.json` 可能被写成 root-owned 0600，导致 PHP-FPM 的 `www` 用户无法读取 active release pointer。

处理方式：

```text
后续所有生产 updater execute 必须使用 Web 运行用户 www 执行，
或确保 pointer / release 目录属主保持 www:www。
```

本次已修正并验证：

```text
storage/updates/current-release.json = www:www 600
public/index.php = www:www 755
cli.php = www:www 755
active release files readable by www
```

## Freeze Rule

1.2.68 现在冻结。

禁止：

- 修改 `v1.2.68` tag
- 覆盖 1.2.68 正式签名包
- 删除 1.2.68 正式包中的 `public/index.php`
- 制作缩水版 1.2.68 替代包
- 在 1.2.68 上继续追加新功能或补丁
- 用生产 hotfix 反向定义 1.2.68

允许：

- 保留 1.2.68 现有 artifact
- 保留 1.2.67.1 compatibility bridge 作为升级链路证据
- 将后续问题登记到 1.2.69
- 在 1.2.69 中修复后续发现的问题

最终冻结声明：

```text
DAIYING_CMS_1_2_68_FINAL_RELEASE = PASS
DAIYING_CMS_1_2_68_ARTIFACTS = FROZEN
NEXT_VERSION_FOR_NEW_WORK = 1.2.69
```
