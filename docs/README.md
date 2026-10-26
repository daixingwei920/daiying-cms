# Daiying CMS Documentation

This index collects the public-facing documentation currently available in the GitHub repository.

## Getting Started

- [Installation](installation.md)
- [Update System](update-system.md)
- [Security Policy](../SECURITY.md)

## AI Product Knowledge

- [Unified V1 facts](../DAIYING_CMS_AI_PRODUCT_KNOWLEDGE_V1.md)
- [Architecture and consumer boundaries](../DAIYING_CMS_AI_PRODUCT_KNOWLEDGE_ARCHITECTURE.md)
- [Knowledge synchronization report 2026-10-14](knowledge/2026-10-14-sync-report.md)
- [知识库到客服闭环及蜘蛛丢样观测隔离验收](knowledge-customer-loop-acceptance.md)
- [客服真实模型补测与旧资料承接建议](knowledge-customer-loop-live-acceptance.md)
- [五篇业务资料审核与迁入验收：业务召回失败](knowledge-article-admission-acceptance.md)
- [检索相关性最小修复：7/7业务召回及冲突、未知、撤回验收](knowledge-relevance-fix-acceptance.md)

- [Issue #9 SEO/GEO configuration and output contract](seo-geo-contract.md) documents the unpublished optional settings, rendering, privacy and rollback behavior.

## User Guide

- [戴影影视插件 0.8.0 安装与配置图文教程](media-sources-0.8.0-guide.zh-CN.md) covers independent movie, short-drama, TV and custom-video setup.
- Content management is available through the admin backend after installation.
- [Administrator Passkey reauthentication candidate](admin-passkey-reauth.md) describes the isolated sensitive-action flow and its limits.
- [Site registration settings](site-registration.md) documents global ICP number configuration in the 1.2.85 candidate.
- [Member collaboration retirement](member-editorial-retirement.md) records the unpublished Issue #9 decision and data retention.
- [Historical Core member editorial access](member-editorial-access.md) documents member registration reuse and limited role assignment.
- Media library capabilities are part of Core and can be extended by storage plugins.
- Site-level AI Provider settings are available through [Global AI](ai.md).
- Site-level mail delivery and notification foundations are available through [Mail Infrastructure](mail-infrastructure.md).
- Scheduled publishing can be run with `php scripts/publish_scheduled_content.php`.

- [国内蜘蛛识别与统计界面（未发布候选）](domestic-crawlers.md)

## Developer Guide

- [Developers](developers.md)
- [Plugins](plugins.md)
- [Plugin SDK V1](plugin-sdk/README.md)
- [Legacy Plugin API v1](../PLUGIN_API_V1.md)
- [Themes](themes.md)
- [Theme Development Specification V1](../DAIYING_THEME_DEVELOPMENT_SPEC_V1.md)
- [Theme API v1](../THEME_API_V1.md)
- [Daiying Theme Framework](../theme-framework/README.md)
- [Global AI](ai.md)
- [Mail Infrastructure](mail-infrastructure.md)
- [Payment Provider Development](payment-providers.md)
- [Foundation Services](foundation-services.md)
- [Content Foundation](content-foundation.md)
- [Core API Stability](../CORE_API_STABILITY.md)
- [Storage Provider API v1](../STORAGE_PROVIDER_API_V1.md)
- [REST API v1](../REST_API_V1.md)
- [Foundation Implementation Report](../DAIYING_CMS_FOUNDATION_IMPLEMENTATION_REPORT.md)
- [Foundation Freeze Checklist](../CORE_FOUNDATION_FREEZE_CHECKLIST.md)
- [Examples](../examples/README.md)

## Commerce

- [Commerce](commerce.md)

## Distribution

- [Distribution](distribution.md)

Distribution is documented as In Development. The current branch contains an alpha Commerce Distribution implementation under `content/plugins/official.commerce`, but it does not yet define a stable standalone Provider/Connector specification or final user workflow.

## Deployment

- Use `public/index.php` as the web entry point.
- Keep `config/`, `storage/`, `system/`, `tests/`, `scripts/`, `content/plugins/`, and `content/themes/` outside public execution.
- Run `php scripts/validate_production_readiness.php --strict` before public launch.

## Architecture Notes

Core namespaces currently visible in the repository include:

- `Cms\Core\Content`
- `Cms\Core\Ai`
- `Cms\Core\Mail`
- `Cms\Core\Media`
- `Cms\Core\Plugin`
- `Cms\Core\Theme`
- `Cms\Core\Market`
- `Cms\Core\Payment`
- `Cms\Core\Update`
- `Cms\Core\Recovery`
- `Cms\Core\ExternalMigration`

Current alpha Distribution work is implemented under the `official.commerce` plugin rather than a stable Core namespace.

## Existing Root Reports

Several historical implementation reports still live at the repository root. They are preserved to avoid breaking references, but new public documentation should link from this index.

- [AI 图像接口、队列与费用规则](ai-image/api.md)（1.2.87）
- [AI 图像与 Logo 测试范围](ai-image/tests.md)

- [通用两级导航及旧主题兼容](navigation-groups.md)（1.2.87 起已发布）

- [Core 1.2.88 与 Logo / Stripe 正式发布记录](CORE_1.2.88_PUBLICATION_REPORT.md)

- [Core1.2.90 PR70合入后的正式签名候选验收](CORE_1.2.90_PR70_PREPARATION_REPORT.md)：新来源449f92a，原冻结包保留，未发布/部署。

- [Core1.2.90正式发布与公开验收](CORE_1.2.90_PUBLICATION_REPORT.md)：GitHub/官方更新、29项匿名检查/19项验签、官网缓存刷新及Owner手动升级分别记录。
