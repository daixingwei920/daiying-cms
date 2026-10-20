# Daiying CMS AI Plugin SDK

Status: Public AI API v1

## Basic Chat

```php
$ai = $context->ai();
$response = $ai->chat([
    ['role' => 'user', 'content' => 'Summarize this product.'],
], [
    'plugin_id' => 'official.commerce',
    'capabilities' => ['text_generation'],
]);
```

Plugins should not inspect which provider the administrator selected unless presenting diagnostics. Provider routing belongs to Core.

## Structured Requests

```php
$response = $context->ai()->request(Cms\Core\Ai\AiRequest::chat($messages, [
    'plugin_id' => 'official.content',
    'operation' => 'summary',
    'capabilities' => ['text_generation', 'structured_output'],
]));
```

## Register a Provider

```php
$context->registerAiProvider(new VendorAiProvider());
```

The plugin must declare `ai.provider`.

## Register a Tool

```php
$context->registerAiTool(
    new Cms\Core\Ai\AiToolDefinition(
        'vendor.product.read',
        'Read Product',
        Cms\Core\Ai\AiToolRisk::READ,
        ['content.read'],
        'vendor.plugin'
    ),
    static fn (array $payload): array => ['ok' => true]
);
```

The plugin must declare `ai.tool`.

## Register an Agent

```php
$context->registerAiAgent(new Cms\Core\Ai\AiAgentDefinition(
    'vendor.product.agent',
    'Product Agent',
    'Reads and prepares product data.',
    ['content.read'],
    ['vendor.product.read'],
    'vendor.plugin'
));
```

The plugin must declare `ai.agent`.

## Register a Prompt

```php
$context->registerAiPrompt(new Cms\Core\Ai\AiPromptTemplate(
    'vendor.product/summary',
    '1.0',
    'Summarize {{title}} for {{site_name}}.',
    ['title', 'site_name'],
    'vendor.plugin'
));
```

The plugin must declare `ai.prompt`.

## Compatibility Rules

Plugins must depend on public AI API v1 classes and `PluginContext`. They must not read AI tables, decrypt secrets, call vendor APIs directly for common chat, or assume a specific provider.


## 图像插件 SDK

Core `1.3.0-alpha.1` 新增实验性图像能力，保留文字契约与站点 Timeout。详见 [图像公共契约](docs/ai-image/api.md)，测试范围见 [测试报告](docs/ai-image/tests.md)。尚未 Stable 发布。


## 请求驱动执行（1.2.88-dev.1，未发布）

`$context->images()->execute($jobId)` 在已验证的会员 POST/CSRF 范围内直接执行当前会员、当前插件的持久图像任务，不需要安装 Worker。`$context->queue()->processMatching($serverOwnedQueueJob)` 可定向执行插件已入队的单个匹配任务；先验证作品所有权再构造任务身份。终态不重放，uncertain 不自动重试；管理员 Timeout、计量和配额保持。详情与主机请求限制见图像公共契约。
