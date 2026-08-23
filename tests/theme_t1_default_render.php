<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Taxonomy\TaxonomyRepository;

require __DIR__ . '/theme_t1_common.php';

$root = theme_t1_root('render');
$repo = new ContentRepository(ConnectionFactory::make(Settings::load($root)), ContentTypeRegistry::defaults());
$repo->create('article', '正式默认主题文章', 'official-default-post', [
    ['type' => 'paragraph', 'data' => ['text' => '这是一段用于首页摘要的中文内容。']],
], 'published', [], ['产品公告'], ['Daiying']);
$repo->create('article', '草稿不应出现在首页', 'draft-post', [
    ['type' => 'paragraph', 'data' => ['text' => 'Draft']],
], 'draft');
$repo->create('page', '关于我们', 'about', [
    ['type' => 'paragraph', 'data' => ['text' => '关于页面内容']],
], 'published');
$taxonomy = new TaxonomyRepository(ConnectionFactory::make(Settings::load($root)));
$taxonomy->ensureTerm('category', '空分类', 'empty-category');

$app = theme_t1_app($root);
$home = $app->handle(new Request('GET', '/'));
$article = $app->handle(new Request('GET', '/articles/official-default-post'));
$page = $app->handle(new Request('GET', '/about'));
$term = ConnectionFactory::make(Settings::load($root))->query("SELECT slug FROM cms_terms WHERE taxonomy = 'category' AND name = '产品公告' LIMIT 1")->fetch();
$category = $app->handle(new Request('GET', '/category/' . (string) ($term['slug'] ?? '')));
$emptyCategory = $app->handle(new Request('GET', '/category/empty-category'));
$missingCategory = $app->handle(new Request('GET', '/category/missing-category'));
$missing = $app->handle(new Request('GET', '/missing'));

theme_t1_check($home->status() === 200 && str_contains($home->body(), 'Daiying 站点') && str_contains($home->body(), '正式默认主题文章'), 'renders official default home with published articles');
theme_t1_check(!str_contains($home->body(), 'PHP CMS Phase 3') && !str_contains($home->body(), '草稿不应出现在首页'), 'home hides development copy and draft content');
theme_t1_check($article->status() === 200 && str_contains($article->body(), '返回列表') && str_contains($article->body(), '产品公告') && str_contains($article->body(), '#Daiying'), 'renders article detail with category, tag and back link');
theme_t1_check($page->status() === 200 && str_contains($page->body(), '页面') && !str_contains($page->body(), '发布于'), 'renders Page with page-specific presentation');
theme_t1_check($category->status() === 200 && str_contains($category->body(), '分类：') && str_contains($category->body(), '正式默认主题文章'), 'renders existing category archive route');
theme_t1_check($emptyCategory->status() === 200 && str_contains($emptyCategory->body(), '分类：空分类') && str_contains($emptyCategory->body(), '该分类暂时没有文章'), 'renders existing empty category as a friendly archive page');
theme_t1_check($missingCategory->status() === 404 && str_contains($missingCategory->body(), 'site-header') && str_contains($missingCategory->body(), '页面未找到'), 'missing category returns themed 404 response');
theme_t1_check($missing->status() === 404 && str_contains($missing->body(), 'site-header') && str_contains($missing->body(), 'main-nav') && str_contains($missing->body(), 'site-footer') && str_contains($missing->body(), '返回首页'), 'missing content returns themed 404 response');
theme_t1_check(!str_contains($missing->body(), 'Exception') && !str_contains($missing->body(), 'SQL') && !str_contains($missing->body(), __DIR__), 'themed 404 response is redacted');

theme_t1_remove($root);
echo "Theme T1 default render tests passed.\n";
