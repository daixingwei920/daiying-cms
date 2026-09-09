<?php

declare(strict_types=1);

const CMS_ROOT = __DIR__ . '/..';

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
};

$read = static function (string $path): string {
    $content = file_get_contents(CMS_ROOT . '/' . $path);
    if (!is_string($content)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $content;
};

$application = $read('system/core/Bootstrap/Application.php');
$admin = $read('system/core/Admin/AdminController.php');
$commercePlugin = $read('content/plugins/official.commerce/plugin.php');
$commerceController = $read('content/plugins/official.commerce/src/CommerceController.php');

$check(str_contains($application, "/admin/login/passkey-options"), 'admin login exposes Passkey authentication options route');
$check(str_contains($application, "/admin/login/passkey-verify"), 'admin login exposes Passkey authentication verification route');
$check(str_contains($admin, 'data-passkey-passwordless-login'), 'login page includes a Passkey passwordless login button');
$check(str_contains($admin, 'navigator.credentials.get'), 'Passkey passwordless login uses WebAuthn browser credentials');
$check(str_contains($admin, "loginUser(\$user)") && str_contains($admin, "passkey_passwordless"), 'Passkey verification creates an admin session without a password');

$check(str_contains($application, "/admin/content/ai-write"), 'content editor exposes the Core AI writing route');
$check(str_contains($admin, 'data-content-ai-write'), 'article/page editor includes the AI writing button');
$check(str_contains($admin, "operation' => 'content.ai_write'"), 'content AI writing is scoped as a Core AI operation');
$check(str_contains($admin, 'new AiService('), 'content AI writing uses the Core AI service');

$check(str_contains($commercePlugin, "/admin/commerce/products/ai-description"), 'Commerce registers the product AI description route');
$check(str_contains($commerceController, 'data-commerce-ai-description'), 'Commerce product form includes the AI description button');
$check(str_contains($commerceController, 'data-commerce-description-editor'), 'Commerce product form has a real description editor target');
$check(str_contains($commerceController, "operation' => 'commerce.product_description'"), 'Commerce product description generation is scoped as a Commerce AI operation');
$check(str_contains($commerceController, '$this->siteAi->chat('), 'Commerce product description generation uses the Core site AI API');
$check(!str_contains($commerceController, 'curl_init(') && !str_contains($commerceController, 'api.deepseek.com') && !str_contains($commerceController, 'api.openai.com'), 'Commerce does not hard-code provider HTTP calls for this AI feature');
$check(str_contains($commerceController, '商品链接仅作为用户填写的参考字段，本次不要抓取网页内容'), 'Commerce AI prompt reserves product URL context without crawling in phase one');
$check(str_contains($commerceController, 'persistProductDescriptionDraft'), 'Commerce saves generated descriptions through CMS content instead of a second description store');

if ($failures > 0) {
    exit(1);
}
