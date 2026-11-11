<?php

declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

/** @var TemplateContext $context */
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $context->e((string) $context->get('title', '页面不可用')) ?></title>
    <link rel="stylesheet" href="<?= $context->e($context->asset('style.css')) ?>">
</head>
<body>
<main class="rs-section rs-error">
    <p>Restaurant Storefront</p>
    <h1><?= $context->e((string) $context->get('title', '页面不可用')) ?></h1>
    <p><?= $context->e((string) $context->get('message', '餐厅店面暂时无法显示，请稍后再试。')) ?></p>
    <a href="/">返回首页</a>
</main>
</body>
</html>
