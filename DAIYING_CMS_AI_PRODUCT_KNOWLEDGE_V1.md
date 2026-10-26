# Daiying CMS AI Product Knowledge V1

知识包：`DAIYING_CMS_AI_PRODUCT_KNOWLEDGE_V1`；修订：`2026-10-26-owner-accepted-pr70-core190-preparation`。

源码基线：Core **1.2.90**，`3ead3e7050cf31785ebf94c52905fce21afd1e31`，`3ead3e7050cf31785ebf94c52905fce21afd1e31`。

复核时间：2026-10-26。本轮仅作源码和文档复核，所有记录的运行测试均为 NOT_RUN_THIS_TASK。

JSON 为事实源；本文件与 USER-FACING 导出由 `python3 scripts/product_knowledge.py --write` 生成。

客服仅使用 USER-FACING 导出；完整包包含 INTERNAL/ADMIN_DEVELOPER，禁止直接送入普通客服上下文。现有旧插件不能被假定已执行这些过滤。

要求 P0 官方知识 → P1 已审核已发布文章 → P2 普通已发布文章；Draft/Pending/C/D、内部资料及分类未知内容默认禁用。参考知识与待优化原文分开。

自动同步状态为 IMPLEMENTED_ISOLATED；原 Core 优化器共享知识接入为 NOT_IMPLEMENTED。隔离验收不代表已部署或生产验收通过。

## Web Installer

`install.web_installer` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

新站安装入口为 /install。支持 SQLite 和 MySQL/MariaDB；安装器执行迁移、创建运行目录、写入 config/app.php 和 storage/installed.lock。安装完成后使用 /admin/login。

- 来源：[docs/installation.md:23](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/installation.md#L23)
- 来源：[system/core/Install/InstallController.php:171](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Install/InstallController.php#L171)

## Runtime Requirements

`install.runtime_requirements` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

安装要求 PHP 8.3.0+ 及 pdo、json、openssl、fileinfo、zip 扩展；健康诊断还检查 curl、mbstring。数据库扩展与连接必须按所选数据库实际满足。

- 来源：[system/core/Support/RuntimeRequirements.php:9](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Support/RuntimeRequirements.php#L9)
- 来源：[system/core/Support/SystemHealthDoctor.php:151](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Support/SystemHealthDoctor.php#L151)

## Content Permalinks

`content.permalinks` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

标准文章链接为 /articles/{slug}，页面链接为 /{slug}。1.2.83 对显式 slug 的书名号兼容另见 content.explicit_book_title_slug；不能据此声称任意字符都受支持。

- 来源：[docs/themes.md:88](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/themes.md#L88)
- 来源：[docs/content-slug-policy.md:3](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/content-slug-policy.md#L3)

## Content revisions and trash internals

`content.revisions_internal` · INTERNAL · P0 · implemented · 测试 NOT_RUN_THIS_TASK

Core 在内容更新及移入回收站前保留修订快照；自动保存草稿与已发布内容分离。delete 移入回收站，永久删除和恢复是独立操作。此条为内部实现依据，不向普通客服暴露类名或方法。

- 来源：[docs/content-foundation.md:5](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/content-foundation.md#L5)
- 来源：[docs/content-foundation.md:26](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/content-foundation.md#L26)

## External migration adapters

`migration.supported_sources_internal` · INTERNAL · P0 · implemented · 测试 NOT_RUN_THIS_TASK

迁移适配器包含 Z-BlogPHP、WordPress、Typecho、Emlog、Halo；服务包含扫描、预演、迁移、重试、续跑及回滚方法。这是源码能力，不代表每个来源已完成真实站点迁移验收。

- 来源：[system/core/ExternalMigration/ExternalMigrationService.php:28](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/ExternalMigration/ExternalMigrationService.php#L28)
- 来源：[system/core/ExternalMigration/ExternalMigrationService.php:189](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/ExternalMigration/ExternalMigrationService.php#L189)

## Signed Core update flow

`update.official_flow` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

已安装网站使用官方更新服务器 https://updates.daiyingcms.com 的签名 Core 更新流程；必须核对清单、SHA-256 和签名。GitHub 完整安装包用于新安装，与在线 Core 更新包分开。

- 来源：[docs/update-system.md:5](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/update-system.md#L5)
- 来源：[docs/update-system.md:36](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/update-system.md#L36)
- 来源：[system/core/Bootstrap/Application.php:613](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Bootstrap/Application.php#L613)

## Recovery diagnostics

`backup.recovery_admin` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

恢复诊断入口为 /admin/recovery，可查看、创建恢复点，以及执行数据库恢复或 Core+数据库恢复。恢复会写入站点，须按权限、备份与人工批准要求处理；入口存在不等于本次已实测恢复。

- 来源：[system/core/Bootstrap/Application.php:578](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Bootstrap/Application.php#L578)
- 来源：[system/core/Recovery/RecoveryController.php:50](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Recovery/RecoveryController.php#L50)

## Site Vault package

`backup.site_vault_internal` · INTERNAL · P0 · implemented · 测试 NOT_RUN_THIS_TASK

Site Vault 可导出 .daiying-site 包，格式为 daiying-site-v1，并校验 manifest/checksums。属于内部实现依据；不能将服务方法写成普通管理员按钮或本轮已验证恢复成功。

- 来源：[system/core/SiteVault/SiteVaultService.php:18](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/SiteVault/SiteVaultService.php#L18)
- 来源：[system/core/SiteVault/SiteVaultService.php:140](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/SiteVault/SiteVaultService.php#L140)

## Production hardening

`security.production_baseline` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

生产部署只以 public/ 为 Web 根目录；私有目录不得暴露，content/uploads 禁止执行 PHP/CGI；使用 HTTPS、secure cookies 和独立 security.encryption_key。上线前可执行 php scripts/validate_production_readiness.php --strict；检查通过不代替人工安全验收。

- 来源：[SECURITY.md:29](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/SECURITY.md#L29)
- 来源：[docs/installation.md:45](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/installation.md#L45)

## Keyword Center UI

`seo.keyword_center` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

关键词中心入口为 /admin/seo/keywords。目标关键词、提交记录、索引证据与实际搜索表现是不同数据，不能把目标值当真实流量。

- 来源：[system/core/Bootstrap/Application.php:527](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Bootstrap/Application.php#L527)
- 来源：[system/core/Seo/Keyword/SeoLifecycleAggregator.php:94](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Seo/Keyword/SeoLifecycleAggregator.php#L94)

## Search Engine connections

`seo.search_engines` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

搜索引擎连接入口为 /admin/seo/search-engines，源码提供 Google Search Console 同步和手工导入入口。百度已提交不等于已收录；搜索表现必须来自实际同步或导入数据，本轮未验证任何站点授权或指标。

- 来源：[system/core/Bootstrap/Application.php:537](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Bootstrap/Application.php#L537)
- 来源：[system/core/Seo/Keyword/SeoLifecycleAggregator.php:348](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Seo/Keyword/SeoLifecycleAggregator.php#L348)

## Sitemap

`seo.sitemap` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

站点地图入口为 /sitemap.xml；源码输出 XML 并包含内容和分类链接。1.2.83 对含书名号 slug 的链接进行编码。页面响应、抓取和搜索引擎收录仍需独立验收。

- 来源：[system/core/Bootstrap/Application.php:432](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Bootstrap/Application.php#L432)
- 来源：[system/core/Content/ContentFrontController.php:303](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Content/ContentFrontController.php#L303)
- 来源：[docs/content-slug-policy.md:7](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/content-slug-policy.md#L7)

## Plugin and theme packages

`plugin.theme_overview` · ADMIN_DEVELOPER · P0 · implemented · 测试 NOT_RUN_THIS_TASK

插件使用 plugin.json，主题使用 theme.json；遵守公开插件/主题 API，业务能力放在插件，主题负责展示。小说和视频产品已退役，历史源码仍存在不代表继续维护；不能根据文件名承诺市场可安装或授权可用。

- 来源：[docs/plugins.md:25](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/plugins.md#L25)
- 来源：[docs/themes.md:28](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/themes.md#L28)
- 来源：[docs/retired-products.md:3](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/retired-products.md#L3)

## Commerce foundation

`commerce.foundation_beta` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

Commerce 仍为 Foundation / Beta，包含插件基础、支付 Provider、支付尝试诊断、付费内容/下载、卡密交付和商业许可存储等能力。商品描述 AI 根据管理员输入生成草稿，不抓取外部商品 URL。不能据此承诺完整税务、结算或真实交易验收。

- 来源：[docs/commerce.md:7](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/commerce.md#L7)
- 来源：[docs/commerce.md:48](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/commerce.md#L48)

## Site AI Provider

`ai.provider_settings` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

站点 AI 设置入口为 /admin/settings/ai，Core 提供站点统一 AI 服务和调用网关。源码包含 OpenAI-compatible、Gemini 和 OpenClaw 适配；插件应复用公开服务而非读取密钥表。客服模型取决于实际站点与插件配置，本知识包不改变模型或站点配置。

- 来源：[system/core/Bootstrap/Application.php:502](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Bootstrap/Application.php#L502)
- 来源：[system/core/Ai/AiService.php:41](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Ai/AiService.php#L41)
- 来源：[system/core/Ai/AiProviderPresets.php:26](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Ai/AiProviderPresets.php#L26)
- 来源：[system/core/Ai/AiGateway.php:25](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Ai/AiGateway.php#L25)

## WordPress comments migration guard

`migration.wordpress_comments_guard` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

不能承诺 WordPress 评论已实现稳定自动迁移。当前迁移代码把评论保留为 pending_feature 记录；管理员须核对当前实际支持范围。适配器能读取数据不等于评论已经写入前台并通过验收。

- 来源：[system/core/ExternalMigration/ExternalMigrationService.php:181](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/ExternalMigration/ExternalMigrationService.php#L181)
- 来源：[system/core/ExternalMigration/ExternalMigrationService.php:628](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/ExternalMigration/ExternalMigrationService.php#L628)

## Daiying CMS 安装入口和步骤

`install.cn_steps` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

准备 PHP 8.3.0+ 和必需扩展，从 GitHub Releases 获取完整安装包，以 public 为 Web 根目录，打开 /install，测试数据库连接并选择 SQLite 或 MySQL/MariaDB（utf8mb4），填写站点和管理员资料后安装。只有迁移、配置与完成锁成功才算完成；失败处理见 install.failure_guard。

- 来源：[docs/installation.md:23](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/installation.md#L23)
- 来源：[docs/first-install-failure.md:5](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/first-install-failure.md#L5)

## 旧站迁移后台入口

`migration.admin_route` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

旧站迁移入口为 /admin/migrations，后台导航名称为“旧站迁移”。源码注册扫描、预演、迁移、续跑、重试和回滚入口；先备份并按当前页面要求核对来源和格式，不保证任何来源都能无损一键迁移。

- 来源：[system/core/Support/View.php:115](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Support/View.php#L115)
- 来源：[system/core/Bootstrap/Application.php:590](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Bootstrap/Application.php#L590)

## 系统更新与恢复入口

`update.recovery_cn` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

系统更新入口为 /admin/update，恢复诊断入口为 /admin/recovery。使用官方签名 Core 更新包；失败后先保留错误和日志，核对恢复点与活动版本状态，按官方恢复流程处理，勿盲目覆盖文件或删除数据库。生产恢复操作须人工批准。

- 来源：[system/core/Support/View.php:116](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Support/View.php#L116)
- 来源：[system/core/Support/View.php:117](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Support/View.php#L117)
- 来源：[docs/first-install-failure.md:3](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/first-install-failure.md#L3)

## 当前正式版本

`release.stable_baseline` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

当前已发布稳定版仍为 Core 1.2.86（v1.2.86，d311446607124fff21d76f524a4a11968ad4cd58）。本包复核已整合图像公共能力并修复支付回调的1.3.0-alpha.5开发候选，不代表正式发布或生产部署。

- 来源：[docs/ai-image/integration-1.2.86.md:3](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/ai-image/integration-1.2.86.md#L3)
- 来源：[docs/payment-refund-webhooks.md:3](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/payment-refund-webhooks.md#L3)

## 书名号固定链接

`content.explicit_book_title_slug` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

1.2.83 支持显式文章、页面和分类 slug 保留《》。留空时仍自动生成简洁 slug，已有 slug 与重定向不自动迁移。仍拒绝分隔符、路径穿越、控制字符、查询/片段符和预编码百分号输入；填写原始书名号，不粘贴 URL 编码。

- 来源：[docs/content-slug-policy.md:3](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/content-slug-policy.md#L3)

## MySQL 安装兼容

`install.mysql_compatibility` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

1.2.82 增加四个校验绑定的历史 MySQL 迁移方言适配，不修改已发布迁移文件。1.2.83 延续该能力；这不代表任意 MySQL/MariaDB 环境均已验收。选择数据库后仍应在安装器测试连接和实际完成安装。

- 来源：[CHANGELOG.md:117](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/CHANGELOG.md#L117)
- 来源：[system/core/Migration/MysqlHistoricalMigrationDialect.php:12](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Migration/MysqlHistoricalMigrationDialect.php#L12)

## 首次安装失败处理

`install.failure_guard` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

首次安装失败须保留错误并修复原因。若管理员已创建但完成锁失败，入口会阻断，不能伪造完成锁或重复创建管理员。MySQL DDL 不保证全链事务回滚；只有负责人明确确认专用数据可丢弃后，才可人工清理重装。生产站不得删除数据库来修复升级。

- 来源：[docs/first-install-failure.md:5](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/first-install-failure.md#L5)
- 来源：[docs/first-install-failure.md:11](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/first-install-failure.md#L11)

## 升级与插件兼容边界

`update.compatibility_boundaries` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

1.2.82 的直接升级验证范围为 1.2.81 → 1.2.82；不能泛化为任意旧版直升最新。实际领取和安装必须服从目标包的签名版本范围与兼容检查。Core 更新不替换已安装插件；旧 Commerce 的 MySQL 参数修复需要独立插件更新。

- 来源：[CHANGELOG.md:120](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/CHANGELOG.md#L120)
- 来源：[CHANGELOG.md:121](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/CHANGELOG.md#L121)
- 来源：[docs/release/1.2.82-upgrade-support.md:4](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/release/1.2.82-upgrade-support.md#L4)

## 小说与视频产品退役

`product.novel_video_retired` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

Owner 于 2026-10-09 决定小说和视频产品退役，不再作为新安装分发、维护或规划产品。保留历史源码、已安装客户资产、订单和许可；退役不等于自动卸载、撤销授权或已完成市场下架。

- 来源：[docs/retired-products.md:3](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/retired-products.md#L3)
- 来源：[docs/retired-products.md:11](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/retired-products.md#L11)

## 历史文章 AI 优化

`ai.historical_optimizer` · USER-FACING · P0 · implemented · 测试 NOT_RUN_THIS_TASK

历史文章 SEO 补全和内容优化入口为 /admin/seo/historical-backfill 与 /admin/seo/content-quality。AI 针对 B/C 级历史文章生成待审核草稿，经统一站点 AI 调用；不自动发布或修改 URL。原优化器尚未接入本统一知识包；不能声称资料自动更新已生效。

- 来源：[system/core/Support/View.php:97](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Support/View.php#L97)
- 来源：[system/core/Seo/Historical/HistoricalArticleSeoBackfillService.php:543](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Seo/Historical/HistoricalArticleSeoBackfillService.php#L543)
- 来源：[system/core/Seo/Historical/HistoricalArticleSeoBackfillService.php:827](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Seo/Historical/HistoricalArticleSeoBackfillService.php#L827)

## SQLite 支付事务内部修复

`payment.sqlite_transaction_internal` · INTERNAL · P0 · implemented · 测试 NOT_RUN_THIS_TASK

1.2.83 按 PDO 连接共享 SQLite immediate transaction 状态，保留事务所有权、支付回调/退款原子性与写锁。属于内部维护修复；不证明真实支付通道、退款或商户交易已验收。

- 来源：[CHANGELOG.md:104](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/CHANGELOG.md#L104)
- 来源：[system/core/Payment/PaymentTransaction.php:8](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Payment/PaymentTransaction.php#L8)

## Global ICP Registration

`site.icp_registration` · ADMIN_DEVELOPER · P0 · implemented_candidate · 测试 NOT_RUN_THIS_TASK

自 1.2.85 起，Core在站点设置中提供全站 ICP 备案号。完整 HTML 主题页面底部显示备案号和工信部查询链接，切换主题仍保留。旧主题专属文字不自动迁移；空值不显示。该功能已随 1.2.85 正式发布。

- 来源：[docs/site-registration.md:3](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/site-registration.md#L3)

## GPT-5 Request Compatibility

`ai.gpt5_parameter_compatibility` · ADMIN_DEVELOPER · P0 · implemented_candidate · 测试 NOT_RUN_THIS_TASK

自 1.2.85 起，Core兼容 GPT-5 系列 Chat Completions 的完成 token 预算，并省略采样温度，连接测试预算为 2048。不会自动更换站点模型；真实外部模型请求未实测；该修复已随 1.2.85 正式发布。

- 来源：[docs/ai.md:5](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/ai.md#L5)

## Administrator Passkey reauthentication candidate

`security.admin_passkey_reauthentication` · ADMIN_DEVELOPER · P0 · implemented_candidate · 测试 NOT_RUN_THIS_TASK

Core 1.2.86允许已登记 Passkey 的管理员用设备用户验证完成受保护操作所需的短时重认证；仍保留密码方式。Passkey 不能修复插件启用失败，该修复已随 1.2.86 正式发布，生产站点仍须升级后才生效；实体设备生物识别未实测。

- 来源：[docs/admin-passkey-reauth.md:1](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/admin-passkey-reauth.md#L1)
- 来源：[system/core/Admin/AdminController.php:1061](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Admin/AdminController.php#L1061)
- 来源：[system/core/Auth/AdminPasskeyService.php:119](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Auth/AdminPasskeyService.php#L119)

## Core Image AI Settings

`ai.image_config` · ADMIN_DEVELOPER · P0 · implemented · 测试 NOT_RUN_THIS_TASK

图像能力复用全站 Provider、加密 API Key 与管理员 Timeout；独立 image_model 默认为关闭，不修改文字模型。当前适配器仅声明官方 OpenAI 端点的 gpt-image-1.5 生成与编辑，其他组合明确拒绝；已随 Core 正式发布，不代表任意账号或服务商均支持图像。

- 来源：[docs/ai-image/api.md:9](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/ai-image/api.md#L9)

## Image Plugin SDK

`ai.image_sdk` · ADMIN_DEVELOPER · P0 · implemented · 测试 NOT_RUN_THIS_TASK

PluginContext::images() 提供同插件、同会员、CSRF 保护的持久任务提交、状态、历史和服务端图像读取；1.2.88 增加 execute 请求执行入口。AiRequest/AiResponse 文字契约保留；插件不保存 Key、不直接调用服务商。

- 来源：[docs/ai-image/api.md:21](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/ai-image/api.md#L21)

## Image Queue Quota and Cost

`ai.image_queue_cost` · ADMIN_DEVELOPER · P0 · implemented · 测试 NOT_RUN_THIS_TASK

图像任务使用原子预占、幂等键与队列CAS。已落盘响应可恢复入库；超时或远端结果未知不自动重发。用户UTC日/分钟限额、usage、管理员预估成本与审计分开记录，成本未知为NULL，不冒充实际账单。

- 来源：[docs/ai-image/api.md:42](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/ai-image/api.md#L42)

## Private Image Media

`ai.image_private_media` · ADMIN_DEVELOPER · P0 · implemented · 测试 NOT_RUN_THIS_TASK

图像原图存入CMS私有媒体，公共媒体入口与公开媒体VM拒绝访问。PNG重编码保留alpha。单色真实SVG描摹属于独立插件，不代表Core原生矢量模型。

- 来源：[docs/ai-image/api.md:54](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/ai-image/api.md#L54)

## Image Validation Scope

`ai.image_validation_scope` · ADMIN_DEVELOPER · P0 · implemented · 测试 NOT_RUN_THIS_TASK

图像接口已有指定账号的真实生成/编辑和 120 秒超时证据；各版本真实范围须分别查验，不能用历史调用替代最新包实测。本条仅复核已发布源码文档；最新包中文/英文、MySQL、免 Worker 和支付验收另见正式发布报告，不保证任意文字或实体设备均已验证。

- 来源：[docs/ai-image/tests.md:18](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/ai-image/tests.md#L18)

## Atomic Plugin Documents and Worker

`plugin.atomic_documents` · ADMIN_DEVELOPER · P0 · implemented · 测试 NOT_RUN_THIS_TASK

Core 正式版提供插件命名空间原子文档和指定类型扩展 Worker，供作品、幂等账本与缓存使用，不赋予普通插件 PDO；已持久化响应可恢复，未知模型结果不自动重放。1.2.88 请求执行与原队列入口兼容。

- 来源：[docs/ai-image/brand-plugin-contracts.md:15](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/ai-image/brand-plugin-contracts.md#L15)

## Administrator Site Branding API

`plugin.branding_admin` · ADMIN_DEVELOPER · P0 · implemented · 测试 NOT_RUN_THIS_TASK

Core 正式版提供管理员站点品牌应用公共契约；验证权限、插件版本及 POST/CSRF，先备份配置，再原子更新站点名称、Logo、Favicon 并记录幂等收据；普通会员调用拒绝。

- 来源：[docs/ai-image/brand-plugin-contracts.md:32](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/ai-image/brand-plugin-contracts.md#L32)

## Public Site Identity

`plugin.public_site_identity` · ADMIN_DEVELOPER · P0 · implemented · 测试 NOT_RUN_THIS_TASK

Core 正式版提供 branding()->current() 公开只读站点身份：仅名称、网址、Logo、Favicon，不返回配置密钥。经营页面读取当前实例身份，客户明确输入的设计品牌独立保留。

- 来源：[docs/ai-image/brand-plugin-contracts.md:44](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/ai-image/brand-plugin-contracts.md#L44)

## Refund callback identity and pending settlement

`payment.refund_callback_idempotency` · ADMIN_DEVELOPER · P0 · implemented · 测试 NOT_RUN_THIS_TASK

Core 正式版按服务商退款 ID 复用同一付款、金额和币种的退款记录，支持 pending 原记录结算与并发幂等；该回调处理不发起退款。相矛盾终态保留失败回执人工核对，不自动恢复权益。Stripe Checkout 映射需独立插件更新；本次发布不调用退款或改变收款设置。

- 来源：[docs/payment-refund-webhooks.md:7](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/payment-refund-webhooks.md#L7)

## Server-resolved checkout return and encrypted recovery

`payment.checkout_recovery` · ADMIN_DEVELOPER · P0 · implemented · 测试 NOT_RUN_THIS_TASK

Core 正式版保留现有会员、报价、权限和支付幂等约束，允许服务端解析的站点名称及返回地址；待付款链接绑定原订单加密保存，跨会话恢复不创建第二笔 Checkout。回到插件页面不能代替已验证付款。

- 来源：[docs/plugin-checkout-recovery.md:5](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/plugin-checkout-recovery.md#L5)

## Configurable two-level navigation

`theme.navigation_groups` · ADMIN_DEVELOPER · P0 · implemented · 测试 NOT_RUN_THIS_TASK

Core 1.2.87 起正式提供后台两级导航、排序、隐藏及按钮配置；保留旧平铺数据与公开接口，新增 menuTree。无数据库迁移；主题须使用树形接口渲染，Core 设置存在不代表任意主题已适配。

- 来源：[docs/navigation-groups.md:5](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/navigation-groups.md#L5)

## Member collaboration retired in Core 1.2.89

`auth.member_editorial_access` · ADMIN_DEVELOPER · P0 · retired_in_published_baseline · 测试 NOT_RUN_THIS_TASK

基线Core1.2.89已退役会员协作：/admin/member-access 与 /workspace 及后代路径对所有方法返回 410，专属服务及运行能力移除；历史迁移、会员、角色授权及草稿数据保留。普通会员、管理员与共享 AI 身份继续保留。正式1.2.88的历史能力不代表当前候选仍开放；生产部署状态另行验收。

- 来源：[docs/member-editorial-retirement.md:5](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/member-editorial-retirement.md#L5)

## Request driven durable AI execution

`ai.request_execution` · ADMIN_DEVELOPER · P0 · implemented · 测试 NOT_RUN_THIS_TASK

Core 1.2.88 允许通过已验证的会员 POST/CSRF 范围执行同插件、同会员的持久 AI 任务；Logo 1.0.1 无需站长配置 Worker。保留现有 AI 配置及 Timeout、状态查询和原 CLI 契约；主机请求期限须容纳模型超时，未知远端结果不自动重放，不保证关闭浏览器后请求仍持续。

- 来源：[docs/ai-image/api.md:69](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/ai-image/api.md#L69)

## Optional SEO GEO outputs and bounded crawler aggregates

`seo.geo_basics` · ADMIN_DEVELOPER · P0 · implemented_in_published_baseline · 测试 NOT_RUN_THIS_TASK

基线Core1.2.89在既有站点设置中增加可选 TDK 模板、公开 Schema/插件元数据实际输出、公开内容目录 llms.txt、分别配置 AI 搜索和训练 robots 声明、来源未验证的有界蜘蛛聚合。保留旧配置/路由/接口；不保证收录或推荐，不提供机器发布 API。详情与已知限制见本次冻结契约。

- 来源：[docs/seo-geo-contract.md:1](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/seo-geo-contract.md#L1)
- 来源：[system/core/Seo/SeoPresentation.php:12](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Seo/SeoPresentation.php#L12)
- 来源：[system/core/Seo/CrawlerLog.php:12](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Seo/CrawlerLog.php#L12)

## 国内蜘蛛识别与统计界面

`seo.domestic_crawler_statistics` · ADMIN_DEVELOPER · P0 · implemented_unpublished_candidate · 测试 NOT_RUN_THIS_TASK

未发布开发分支识别百度、搜狗、360、神马、Bytespider、头条和Petal的有限UA标记，别名合并；后台SEO蜘蛛统计支持时间、平台、蜘蛛与HTTP状态筛选、UTC趋势、排名和分页。沿用有界私有聚合，不保存IP、原始UA或查询串；来源未验证，国内分组不代表IP所在地，不保证收录或AI引用。 所有计数明确为已采集样本；非阻塞锁和容量边界可能丢样，缺少完整分母，不能推算访问总量或采集率。隔离升级回滚已补测，正式签名和真机仍阻塞。

- 来源：[system/core/Seo/CrawlerRegistry.php:11](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Seo/CrawlerRegistry.php#L11)
- 来源：[system/core/Seo/CrawlerStatistics.php:21](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Seo/CrawlerStatistics.php#L21)
- 来源：[docs/domestic-crawlers.md:7](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/domestic-crawlers.md#L7)
- 来源：[docs/pr67-supplemental-acceptance.md:44](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/pr67-supplemental-acceptance.md#L44)

## Isolated Customer Knowledge Closure

`knowledge.customer_consumer_loop` · ADMIN_DEVELOPER · P0 · implemented_unpublished_candidate · 测试 NOT_RUN_THIS_TASK

知识同步工具及独立客服1.1.1-dev.2候选已实现隔离发布事件、事务导入回执、失败保旧、重试及撤回。此前泛化P0屏蔽相关P1的同一组业务问题0/7；最小相关性修复后先筛选主题再按相关P0/P1/P2排序，新隔离原生升级、9项专项、21项回归、41项真实模型HTTP检查及撤回后同题旧FAQ检查通过，业务7/7。仍为未发布候选，测试批次已撤回；多实体和500候选上限限制保留，未生产导入、部署或接入Core优化器。 Owner已于2026-10-26接受本轮隔离验收并授权合入1.2.90、另行签名/Gate准备；原冻结包保留，未授权正式发布或生产部署。

- 来源：[docs/knowledge-customer-loop-acceptance.md:16](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/knowledge-customer-loop-acceptance.md#L16)
- 来源：[docs/knowledge-customer-loop-live-acceptance.md:13](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/knowledge-customer-loop-live-acceptance.md#L13)
- 来源：[docs/knowledge-article-admission-acceptance.md:47](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/knowledge-article-admission-acceptance.md#L47)
- 来源：[docs/knowledge-relevance-fix-acceptance.md:30](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/docs/knowledge-relevance-fix-acceptance.md#L30)

## Crawler Collection Loss Observation Candidate

`seo.crawler_loss_observation` · ADMIN_DEVELOPER · P0 · implemented_unpublished_candidate · 测试 NOT_RUN_THIS_TASK

未发布开发分支补充开启采集后的结果观测：写入成功、锁忙、容量、损坏和读写失败等。固定8个非阻塞分片、每片8KiB，复用保留期且不保存IP/UA/查询串/具体slug。后台只跟随时间窗口并明确下界口径；不能计算真实访问量或完整丢样率，不属于已冻结1.2.90包。

- 来源：[system/core/Seo/CrawlerObservation.php:10](https://github.com/daixingwei920/daiying-cms/blob/3ead3e7050cf31785ebf94c52905fce21afd1e31/system/core/Seo/CrawlerObservation.php#L10)
