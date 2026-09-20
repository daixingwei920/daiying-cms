<?php declare(strict_types=1); /** @var Cms\Core\Theme\TemplateContext $context */ ?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $context->e((string) $context->get('title', 'Not found')) ?></title>
  <link rel="stylesheet" href="<?= $context->e($context->asset('css/theme.css')) ?>">
</head>
<body class="blank-theme">
  <main class="page">
    <h1><?= $context->e((string) $context->get('title', 'Not found')) ?></h1>
    <p><?= $context->e((string) $context->get('message', 'The requested page could not be found.')) ?></p>
    <p><a href="/">Back to home</a></p>
  </main>
</body>
</html>
