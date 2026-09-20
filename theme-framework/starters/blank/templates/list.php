<?php
declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

/** @var TemplateContext $context */
require_once dirname(__DIR__) . '/_theme.php';
$items = blank_items($context);
$pagination = $context->get('pagination', []);
$pagination = is_array($pagination) ? $pagination : [];
$page = max(1, (int) ($pagination['page'] ?? 1));
$perPage = max(1, (int) ($pagination['per_page'] ?? 10));
$totalItems = max(count($items), (int) ($pagination['total'] ?? count($items)));
$totalPages = max(1, (int) ceil($totalItems / $perPage));
$basePath = (string) $context->get('base_path', '/articles');
$pager = $context->pagination($page, $totalPages, $basePath, [], $perPage, $totalItems);
?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $context->e((string) $context->get('title', 'Articles')) ?></title>
  <link rel="stylesheet" href="<?= $context->e($context->asset('css/theme.css')) ?>">
</head>
<body class="blank-theme">
  <main class="page">
    <h1><?= $context->e((string) $context->get('page_title', $context->get('title', 'Articles'))) ?></h1>
    <div class="card-grid">
      <?php foreach ($items as $item): $url = blank_content_url($item); ?>
        <article class="card">
          <h2><a href="<?= $context->e($url) ?>"><?= $context->e((string) ($item['title'] ?? '')) ?></a></h2>
          <?php if (($excerpt = blank_excerpt($item)) !== ''): ?><p><?= $context->e($excerpt) ?></p><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
    <?php if (($pager['total_items'] ?? 0) > $perPage): ?>
      <nav class="pagination" aria-label="Pagination">
        <?php if (!empty($pager['prev_url'])): ?><a href="<?= $context->e((string) $pager['prev_url']) ?>">Previous</a><?php endif; ?>
        <?php foreach (($pager['pages'] ?? []) as $entry): ?>
          <?php if (!empty($entry['current'])): ?><span aria-current="page"><?= (int) $entry['page'] ?></span><?php else: ?><a href="<?= $context->e((string) $entry['url']) ?>"><?= (int) $entry['page'] ?></a><?php endif; ?>
        <?php endforeach; ?>
        <?php if (!empty($pager['next_url'])): ?><a href="<?= $context->e((string) $pager['next_url']) ?>">Next</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </main>
</body>
</html>
