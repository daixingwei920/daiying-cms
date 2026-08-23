<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Content\BlockRenderer;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Theme\ThemeManager;

require __DIR__ . '/theme_t1_common.php';

$root = theme_t1_root('escape');
$theme = (new ThemeManager($root . '/content/themes', Settings::load($root), new FileLogger($root . '/storage/logs/app.log')))->load('default');
$blocks = [
    ['type' => 'paragraph', 'data' => ['text' => '<script>alert(1)</script><b>正文</b>']],
    ['type' => 'code', 'data' => ['language' => 'php', 'code' => '<?php echo "<x>";']],
];
$html = $theme->render('content', [
    'site_name' => '<img src=x onerror=alert(1)>',
    'title' => '<script>alert(1)</script>标题',
    'content' => ['content_type' => 'article', 'slug' => 'escape'],
    'rendered_blocks' => (new BlockRenderer())->render($blocks),
    'categories' => [['name' => '<b>分类</b>', 'slug' => 'cat"><script>']],
    'tags' => [['name' => '<i>标签</i>', 'slug' => 'tag"><script>']],
    'seo' => ['title' => '<script>SEO</script>', 'description' => '描述 " < >', 'canonical' => 'https://example.test/a?x=1&y=2'],
]);

theme_t1_check(!str_contains(strtolower($html), '<script') && !str_contains(strtolower($html), '<img'), 'escapes title, site name and taxonomy text');
theme_t1_check(str_contains($html, '&lt;script&gt;SEO&lt;/script&gt;') && str_contains($html, '描述 &quot; &lt; &gt;'), 'escapes SEO title and description');
theme_t1_check(str_contains($html, '&lt;b&gt;正文&lt;/b&gt;') && str_contains($html, '&lt;?php echo &quot;&lt;x&gt;&quot;;'), 'keeps rendered content escaped by the core block renderer');
theme_t1_check(str_contains($html, 'https://example.test/a?x=1&amp;y=2'), 'escapes canonical URL attributes');

theme_t1_remove($root);
echo "Theme T1 content escape tests passed.\n";
