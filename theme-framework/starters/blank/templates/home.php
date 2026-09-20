<?php
declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

/** @var TemplateContext $context */
require_once dirname(__DIR__) . '/_theme.php';
$seo = $context->seo();
$items = blank_items($context);
?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $context->e((string) ($seo['title'] ?? $context->get('site_name', 'Site'))) ?></title>
  <link rel="stylesheet" href="<?= $context->e($context->asset('css/theme.css')) ?>">
</head>
<body class="blank-theme">
  <header class="site-header">
    <a class="brand" href="/">
      <?php if (($logo = blank_logo_url($context)) !== ''): ?>
        <img src="<?= $context->e($logo) ?>" alt="<?= $context->e($context->get('site_name', 'Site')) ?>">
      <?php else: ?>
        <span><?= $context->e($context->get('site_name', 'Site')) ?></span>
      <?php endif; ?>
    </a>
  </header>
  <main>
    <section class="hero">
      <p><?= $context->e((string) $context->setting('hero_eyebrow', 'Theme Starter')) ?></p>
      <h1><?= $context->e((string) $context->setting('hero_title', 'Build your next Daiying CMS theme')) ?></h1>
      <a href="#latest">Read latest</a>
    </section>
    <section id="latest" class="content-list">
      <h2><?= $context->e((string) $context->setting('home_section_title', 'Latest Articles')) ?></h2>
      <div class="card-grid">
        <?php foreach ($items as $item): $url = blank_content_url($item); ?>
          <article class="card">
            <?php if (($cover = blank_cover($item)) !== ''): ?><a href="<?= $context->e($url) ?>"><img src="<?= $context->e($cover) ?>" alt=""></a><?php endif; ?>
            <h3><a href="<?= $context->e($url) ?>"><?= $context->e((string) ($item['title'] ?? '')) ?></a></h3>
            <?php if (($excerpt = blank_excerpt($item)) !== ''): ?><p><?= $context->e($excerpt) ?></p><?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  </main>
  <script defer src="<?= $context->e($context->asset('js/theme.js')) ?>"></script>
</body>
</html>
