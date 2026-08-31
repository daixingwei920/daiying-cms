<?php

declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

/** @var TemplateContext $context */
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $context->e($context->get('site_name', 'PHP CMS')) ?></title>
    <style>
        body{margin:0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#fff;color:#1f2937}
        main{max-width:760px;margin:48px auto;padding:0 20px}
        .box{border:1px solid #d8dee8;border-radius:8px;padding:20px}
        .muted{color:#667085}
    </style>
</head>
<body>
<main>
    <section class="box">
        <h1><?= $context->e($context->get('site_name', 'PHP CMS')) ?></h1>
        <p class="muted">安全主题正在运行。请在后台检查当前主题。</p>
    </section>
</main>
</body>
</html>
