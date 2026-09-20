<?php
declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

/** @var TemplateContext $context */
require_once dirname(__DIR__) . '/components/theme_helpers.php';
$content = $context->get('content', $context->get('article', []));
$content = is_array($content) ? $content : [];
$title = (string) ($content['title'] ?? $context->get('title', ''));
$body = (string) $context->get('rendered_blocks', $content['body'] ?? '');
$previous = $context->get('previous');
$next = $context->get('next');
?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $context->e($title) ?></title>
  <link rel="stylesheet" href="<?= $context->e($context->asset('css/immersive-culture.css')) ?>">
</head>
<body class="ic-theme">
  <main class="ic-article">
    <h1><?= $context->e($title) ?></h1>
    <article class="ic-prose"><?= $body ?></article>
    <?php if (is_array($previous) || is_array($next)): ?>
      <nav class="ic-adjacent" aria-label="Adjacent articles">
        <?php if (is_array($previous)): ?><a href="<?= $context->e((string) ($previous['url'] ?? ic_content_url($previous))) ?>">Previous: <?= $context->e((string) ($previous['title'] ?? '')) ?></a><?php endif; ?>
        <?php if (is_array($next)): ?><a href="<?= $context->e((string) ($next['url'] ?? ic_content_url($next))) ?>">Next: <?= $context->e((string) ($next['title'] ?? '')) ?></a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </main>
</body>
</html>
