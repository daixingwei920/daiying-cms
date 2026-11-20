# 戴影 CMS 中文安装教程

[返回中文项目介绍](../README.zh-CN.md) · [English](installation.md) · [官网中文文档](https://www.daiyingcms.com/docs)

本教程面向首次部署的站长，依据 Core **1.2.100** 正式发布包与安装器流程整理。正式版发布于 **2026-11-16**；GitHub `main` 可能包含尚未发布的维护代码，请以[正式 Release](https://github.com/daixingwei920/daiying-cms/releases/latest)选择安装包。已有站点请先备份，再按[原生更新流程](update-system.md)升级。

## 环境准备

| 项目 | 要求 |
| --- | --- |
| PHP | 最低 8.3.0；本正式版已测试 PHP 8.3 / 8.5 |
| PHP 扩展 | `pdo`、`json`、`openssl`、`fileinfo`、`zip` |
| 数据库 | SQLite（启用 `pdo_sqlite`），或 MySQL/MariaDB（启用 `pdo_mysql`、使用 `utf8mb4`） |
| Web 服务 | 能将请求交给 `public/index.php` 的 Apache、Nginx 等；公开站点使用 HTTPS |
| 站点用户 | 安装时能写入 `config/`、`storage/` 和上传目录的 PHP 运行用户 |

确认网站实际使用的 PHP 版本和扩展；如需运行 CLI 命令，也要检查命令行 PHP，二者可能使用不同配置。

## 下载与校验

从[官方 v1.2.100 Release](https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.100)下载：

- [完整安装包：daiying-cms-1.2.100-stable.zip](https://github.com/daixingwei920/daiying-cms/releases/download/v1.2.100/daiying-cms-1.2.100-stable.zip)
- [SHA-256 文件](https://github.com/daixingwei920/daiying-cms/releases/download/v1.2.100/daiying-cms-1.2.100-stable.zip.sha256)
- [签名信息](https://github.com/daixingwei920/daiying-cms/releases/download/v1.2.100/daiying-cms-1.2.100-stable.zip.signature.json)

Linux 在下载目录执行：

```sh
sha256sum -c daiying-cms-1.2.100-stable.zip.sha256
```

macOS 使用 `shasum -a 256 daiying-cms-1.2.100-stable.zip`；Windows PowerShell 使用 `Get-FileHash .\daiying-cms-1.2.100-stable.zip -Algorithm SHA256`。结果应为：

```text
000eda96213415ae3c9f803ccbd8f9e6bc2e76c03f224ba16f5572ab5e0934f0
```

校验不一致时停止使用该下载文件，重新从官方 Release 获取。Gitee 镜像提供源码与文档；GitHub/Gitee 的自动源码 ZIP、Git 克隆目录及 Core 更新 ZIP 都不作为首次安装包使用。

## 上传与站点目录

1. 将完整安装包上传并解压到站点目录，例如 `/path/to/daiying-cms/`。
2. 将 Web 服务器的**文档根目录**设置为 `/path/to/daiying-cms/public/`。PHP 入口是 `public/index.php`，不将整个项目目录设为公开目录。
3. 为域名配置 HTTPS、对应的 PHP 运行环境及请求转发。Apache 需要启用 URL 重写并允许安装包中 `public/.htaccess` 生效；Nginx 可参考下面的入口规则。
4. 将必要写权限授予运行该站点的 PHP 用户。安装器会检测 `config/` 与 `storage/`；上传使用 `content/uploads/`。日志、缓存、SQLite 和恢复点等子目录在 `storage/` 下，不必为整个项目设置全员可写权限。

保留项目自带的默认主题及安全规则，确保 `config/`、数据库和运行日志不被 Web 服务器直接公开。

Nginx 在文档根目录已经设为 `public/` 的站点中，可使用以下规则将其他路径交给应用入口；PHP-FPM 处理规则须按实际 PHP 服务配置：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

## 浏览器安装

1. 打开 `https://你的域名/install`，先处理安装器列出的 PHP、扩展及目录权限问题。
2. 选择数据库：SQLite 文件路径需在 CMS 项目目录内且可写；MySQL/MariaDB 填写主机、端口、数据库名、用户名和密码。
3. 点击数据库连接测试，通过后再继续。
4. 填写站点名称、公开站点 URL、管理员邮箱、显示名称与密码。安装器允许站点 URL 留空，公开部署时建议填写实际 HTTPS 地址。
5. 提交安装。安装器会执行 Core 数据库迁移、写入 `config/app.php`，并生成 `storage/installed.lock`。
6. 进入 `/admin/login`，使用刚设置的管理员账号登录。已安装后再访问 `/install` 会转到后台登录入口。

## 安装完成后检查

- 访问 `/health`，确认安装状态和实际运行版本。
- 登录后台检查内容、媒体、主题和插件入口；再按站点需要安装并配置扩展。
- 在项目根目录运行生产就绪检查，并按输出处理实际问题：

```sh
php scripts/validate_production_readiness.php
php scripts/validate_production_readiness.php --strict
```

`--strict` 在存在阻塞问题时返回非零退出码。检查器不能代替你在实际服务器上的安装、页面、上传与业务功能验证。

## 按需配置定时任务（Core 1.2.100）

如果启用的功能依赖已登记的 Core 或插件定时任务，由管理员以**该站点的操作系统用户**配置每分钟一次的 cron。安装或升级 Core 不会自动创建系统 cron。

示例中的路径须替换为实际项目目录和 PHP 8.3+ 可执行文件：

```cron
* * * * * cd /path/to/daiying-cms && /path/to/php cli.php run-scheduled-tasks >> storage/logs/scheduler-cron.log 2>&1
```

该入口执行已经到期、符合现有启用条件的注册任务，并使用站点级非阻塞进程锁防止重叠运行。外部服务凭据、插件启用状态和业务配置仍需按对应功能设置。详见 [1.2.100 发布说明](CORE_1.2.100_PUBLICATION_REPORT.md)。

## 常见问题

| 现象 | 优先检查 |
| --- | --- |
| `/install` 或其他路径打不开 | 文档根目录是否为 `public/`，请求是否转发至 `public/index.php` |
| PHP 或扩展检查不通过 | 网站实际使用的 PHP 版本与扩展，不只检查终端中的 PHP |
| 配置或 Storage 不可写 | PHP 运行用户是否拥有对应目录所需权限 |
| 数据库连接失败 | 所选 PDO 驱动、连接信息、数据库权限；SQLite 路径是否在项目内且可写 |
| 下载后文件结构或安装入口不对 | 是否下载了完整安装包，而不是更新 ZIP 或源码归档 |
| 后台无法确认新版本 | 查看当前正式 Release 和[更新文档](update-system.md)，保留现有恢复点并记录实际错误 |

其他入口：[安全策略](../SECURITY.md) · [更新与恢复文档](update-system.md) · [问题反馈](https://github.com/daixingwei920/daiying-cms/issues)。
