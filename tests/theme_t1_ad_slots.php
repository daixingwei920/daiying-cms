<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;

require __DIR__ . '/theme_t1_common.php';

$root = theme_t1_root('ad-slots');
$config = require $root . '/config/app.php';
$config['theme']['settings']['default']['ad_slots'] = [
    'home_top' => [
        'label' => '首页广告位',
        'html' => '<p><strong>首页横幅广告位</strong></p><script>alert(1)</script>',
    ],
    'article_top' => [
        'label' => '文章顶部广告位',
        'html' => '<a href="javascript:alert(1)" onclick="alert(2)">文章顶部广告</a>',
    ],
    'article_bottom' => [
        'label' => '文章底部广告位',
        'html' => '<a href="https://ads.example.test/campaign">文章底部广告</a>',
    ],
    'archive_top' => [
        'label' => '归档广告位',
        'html' => '<img src="https://cdn.example.test/banner.jpg" alt="归档广告">',
    ],
    'sidebar' => [
        'label' => '侧栏广告位',
        'html' => '<p>侧栏广告位</p>',
    ],
    'footer_top' => [
        'label' => '页脚广告位',
        'html' => '<p>页脚广告位</p>',
    ],
];
theme_t1_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");

$repo = new ContentRepository(ConnectionFactory::make(Settings::load($root)), ContentTypeRegistry::defaults());
$repo->create('article', '广告位测试文章', 'ad-slot-post', [
    ['type' => 'paragraph', 'data' => ['text' => '用于测试主题广告插槽的文章。']],
], 'published');

$app = theme_t1_app($root);
$home = $app->handle(new Request('GET', '/'));
$article = $app->handle(new Request('GET', '/articles/ad-slot-post'));
$list = $app->handle(new Request('GET', '/articles'));

theme_t1_check($home->status() === 200 && str_contains($home->body(), '首页横幅广告位'), 'renders configured home ad slot');
theme_t1_check($article->status() === 200 && str_contains($article->body(), '文章顶部广告') && str_contains($article->body(), '文章底部广告'), 'renders configured article ad slots');
theme_t1_check($list->status() === 200 && str_contains($list->body(), '归档广告') && str_contains($list->body(), '侧栏广告位'), 'renders configured archive and sidebar ad slots');
theme_t1_check(str_contains($article->body(), '页脚广告位'), 'renders configured footer ad slot');

foreach ([$home->body(), $article->body(), $list->body()] as $body) {
    theme_t1_check(!str_contains(strtolower($body), '<script'), 'ad slot strips script tags');
    theme_t1_check(!str_contains(strtolower($body), 'onclick='), 'ad slot strips event handlers');
    theme_t1_check(!str_contains(strtolower($body), 'javascript:'), 'ad slot strips javascript URLs');
}

theme_t1_remove($root);
echo "Theme T1 ad slot tests passed.\n";
