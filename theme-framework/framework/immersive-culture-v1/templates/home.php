<?php
declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

/** @var TemplateContext $context */
require_once dirname(__DIR__) . '/components/theme_helpers.php';
$seo = $context->seo();
$items = ic_items($context);
?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $context->e((string) ($seo['title'] ?? $context->get('site_name', 'Daiying CMS'))) ?></title>
  <link rel="stylesheet" href="<?= $context->e($context->asset('css/immersive-culture.css')) ?>">
</head>
<body class="ic-theme">
  <header class="ic-site-header">
    <a class="ic-brand" href="/">
      <?php if (($logo = ic_logo_url($context)) !== ''): ?>
        <img src="<?= $context->e($logo) ?>" alt="<?= $context->e($context->get('site_name', 'Site')) ?>">
      <?php else: ?>
        <span><?= $context->e($context->get('site_name', 'Site')) ?></span>
      <?php endif; ?>
    </a>
  </header>
  <main>
    <section class="ic-hero" data-transition="scroll-open">
      <div class="ic-layer ic-layer-background"></div>
      <div class="ic-layer ic-layer-environment"></div>
      <div class="ic-layer ic-layer-figures"></div>
      <div class="ic-layer ic-layer-foreground"></div>
      <div class="ic-layer ic-layer-effects"></div>
      <div class="ic-hero-copy">
        <p><?= $context->e((string) $context->setting('hero_eyebrow', 'Daiying CMS Theme')) ?></p>
        <h1><?= $context->e((string) $context->setting('hero_title', 'Create an immersive site')) ?></h1>
      </div>
    </section>
    <section class="ic-content-section" id="content">
      <h2><?= $context->e((string) $context->setting('home_section_title', 'Latest Articles')) ?></h2>
      <div class="ic-grid">
        <?php foreach ($items as $item): $url = ic_content_url($item); ?>
          <article class="ic-card">
            <?php if (($cover = ic_cover($item)) !== ''): ?><a href="<?= $context->e($url) ?>"><img src="<?= $context->e($cover) ?>" alt=""></a><?php endif; ?>
            <h3><a href="<?= $context->e($url) ?>"><?= $context->e((string) ($item['title'] ?? '')) ?></a></h3>
            <?php if (($excerpt = ic_excerpt($item)) !== ''): ?><p><?= $context->e($excerpt) ?></p><?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  </main>
  <script defer src="<?= $context->e($context->asset('js/immersive-culture.js')) ?>"></script>
</body>
</html>
