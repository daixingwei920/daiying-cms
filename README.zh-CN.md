# Daiying CMS

[English](README.md)

Daiying CMS 是一套模块化 PHP CMS，用于构建内容网站、媒体流程、商业插件和面向分发的站点运营能力。

当前公开稳定版本：**1.2.24 stable**。

## 项目入口

- 官网：<https://www.daiyingcms.com>
- 文档导航：[docs/README.md](docs/README.md)
- 下载：<https://github.com/daixingwei920/daiying-cms/releases/latest>
- Releases：<https://github.com/daixingwei920/daiying-cms/releases>
- 插件文档：[docs/plugins.md](docs/plugins.md)
- 主题文档：[docs/themes.md](docs/themes.md)

## 产品层级

Daiying CMS 不是单一功能脚本，而是按产品层级组织：

1. **Daiying CMS Core**：安装器、后台、路由、内容、媒体、主题、插件生命周期、恢复诊断、签名更新。
2. **Content / Media**：文章、页面、结构化区块、分类、媒体库、上传、外部媒体与存储接入。
3. **Commerce**：支付 Provider 基础、付费内容/下载、自动发卡、商业产品和授权存储。
4. **Distribution**：**开发中 / 预览**。用于围绕商业扩展、授权、交付、更新和站点能力包建立统一分发流程。当前仓库已有市场任务、商业授权、插件安装、更新检查、发卡和支付基础，但稳定的 Distribution Provider 规范还没有在公开仓库中定稿。
5. **Plugins / Themes**：插件和主题包生态。
6. **Update System**：签名 Core 更新、恢复点、完整性检查、健康检查和回滚路径。

## 快速开始

### 1. 环境要求

- PHP 8.3.0 或更高版本。
- PHP 扩展：`pdo`、`json`、`openssl`、`fileinfo`、`zip`。
- SQLite，或 MySQL/MariaDB `utf8mb4`。
- Web 服务器应将站点入口指向 `public/index.php`。
- `storage/` 和 `content/uploads/` 需要 PHP 进程可写。

### 2. 下载

从 [GitHub Releases](https://github.com/daixingwei920/daiying-cms/releases/latest) 下载最新稳定安装包。

当前检查到的发行信息：

- Tag：`v1.2.24`
- 安装包：`daiying-cms-1.2.24-stable.zip`
- SHA-256：`689ecb05b0a461bc5cdc5393589baf9eac8fdf2bb3c1b75e7e4b159e609b79ee`

### 3. 安装

1. 上传并解压安装包。
2. 将 Web 服务器站点目录指向 `public`。
3. 确保 `storage/` 和 `content/uploads/` 可写。
4. 打开 `/install`。
5. 选择 SQLite 或 MySQL/MariaDB，并测试数据库连接。
6. 填写站点身份和第一个管理员账号。
7. 通过 `/admin/login` 登录后台。

详细检查项见：[安装文档](docs/installation.md)。

## 重点能力

### Distribution

**状态：开发中 / 预览**

Distribution 是 Daiying CMS 当前正在建设的差异化方向，目标是让 CMS 不只管理内容，也能围绕插件、主题、授权、交付和更新形成可运营的分发体系。

当前公开仓库已经能看到的基础能力包括：

- 市场包安装与更新任务。
- 商业产品与授权表。
- 站点授权激活存储。
- 自动发卡交付。
- 支付 Provider 基础。
- 签名 Core 更新基础设施。

稳定的 Distribution Provider API 和最终用户流程，需要等相关开发线程完成后再进入 Stable 文档。

### Commerce

**状态：基础能力 / Beta**

Core 已包含支付 Provider 设置、支付记录、付费内容/下载授权和自动发卡。当前 Core 自带人工确认支付和 hosted redirect Provider 基础，Stripe、PayPal、支付宝、微信支付等能力可作为 Provider 插件接入。

见：[Commerce 文档](docs/commerce.md)。

### 插件生态

插件可通过本地 ZIP 或官方市场安装。生命周期覆盖 manifest 校验、安全解压、静态扫描、依赖检查、原子安装、数据保留策略、迁移、重装恢复和能力边界。

当前仓库可确认的插件包括：

- `official.friend-links`
- `official.novel-collector`
- `official.video-collector`
- `local.storage.baidu`
- `faq_block`

见：[插件文档](docs/plugins.md)。

### 主题生态

主题独立放在 `content/themes`，通过 `theme.json` 声明主题 ID、版本、兼容 Core、支持内容类型、模板、资源和设置项。

当前仓库可确认的主题包括：

- `default`
- `daiying_media`
- `daiying_novel`
- `daiying-video`
- `safe`

见：[主题文档](docs/themes.md)。

## 文档

- [文档导航](docs/README.md)
- [安装部署](docs/installation.md)
- [开发者入口](docs/developers.md)
- [插件](docs/plugins.md)
- [主题](docs/themes.md)
- [Commerce](docs/commerce.md)
- [Distribution](docs/distribution.md)
- [Update System](docs/update-system.md)
- [安全策略](SECURITY.md)
- [贡献指南](CONTRIBUTING.md)

## 更新

已安装站点使用的官方更新服务器：

```text
https://updates.daiyingcms.com
```

生产环境上线前建议运行：

```sh
php scripts/validate_production_readiness.php
php scripts/validate_production_readiness.php --json
php scripts/validate_production_readiness.php --strict
```

版本历史见：[CHANGELOG.md](CHANGELOG.md)。

## 截图

当前仓库还没有可确认的公开截图文件。后续需要补充的截图清单见：[GITHUB_SCREENSHOT_REQUIREMENTS.md](GITHUB_SCREENSHOT_REQUIREMENTS.md)。

## 授权

当前仓库没有 `LICENSE` 文件。项目所有者明确授权方式前，请不要假设第三方可自由复用、分发或商用。
