<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Theme\ThemeManager;

require __DIR__ . '/theme_t1_common.php';

$root = theme_t1_root('responsive');
$theme = (new ThemeManager($root . '/content/themes', Settings::load($root), new FileLogger($root . '/storage/logs/app.log')))->load('default');
$html = $theme->render('content', [
    'site_name' => '很长的中文站点名称用于响应式检查',
    'title' => '这是一个很长很长的中文标题 with-super-long-english-word-that-must-not-break-the-layout',
    'content' => ['content_type' => 'article', 'slug' => 'long-title'],
    'rendered_blocks' => '<div class="entry-content"><p>https://example.test/' . str_repeat('longword', 20) . '</p><pre><code>' . str_repeat('code ', 80) . '</code></pre><table><tbody><tr><td>' . str_repeat('cell', 40) . '</td></tr></tbody></table><audio controls preload="metadata"></audio><figure><img src="/media/1/photo.png" alt="图片"></figure></div>',
    'published_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
    'seo' => ['title' => '响应式检查', 'description' => '检查移动端标记'],
]);

foreach (['width=device-width,initial-scale=1', '@media (max-width: 760px)', '@media (max-width: 380px)', 'overflow-x:hidden', 'overflow-wrap:anywhere', 'max-width:100%', 'table{width:100%;border-collapse:collapse;display:block;overflow-x:auto}', 'pre{max-width:100%;overflow:auto', 'class="nav-toggle"', 'aria-label="主导航"'] as $needle) {
    theme_t1_check(str_contains($html, $needle), 'responsive markup contains ' . $needle);
}

theme_t1_remove($root);
echo "Theme T1 responsive markup tests passed.\n";
