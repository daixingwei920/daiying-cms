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
