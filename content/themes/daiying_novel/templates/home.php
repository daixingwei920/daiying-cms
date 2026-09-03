<?php

declare(strict_types=1);

/** @var object $context */
$e = static fn ($v): string => method_exists($context, 'e') ? $context->e((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$get = static fn (string $key, $default = null) => method_exists($context, 'get') ? $context->get($key, $default) : $default;
$urls = require __DIR__ . '/_helpers.php';
$novelUrl = $urls['novel_url'];
$novelSearchUrl = $urls['novel_search_url'];
$novelBookshelfUrl = $urls['novel_bookshelf_url'];
$site = $get('site_name', 'Daiying Novel');
$sections = $get('novel_sections', []);
$sections = is_array($sections) ? $sections : [];
if ($sections === []) {
    $sections = (static function (): array {
        $root = dirname(__DIR__, 4);
        $configFile = $root . '/config/app.php';
        if (!is_file($configFile)) {
            return [];
        }
        try {
            $config = require $configFile;
            $db = is_array($config) ? ($config['database'] ?? []) : [];
            if (!is_array($db) || (string) ($db['dsn'] ?? '') === '') {
                return [];
            }
            $pdo = new PDO((string) $db['dsn'], ($db['username'] ?? '') !== '' ? (string) $db['username'] : null, ($db['password'] ?? '') !== '' ? (string) $db['password'] : null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
                $stmt->execute(['novels']);
            } else {
                $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
                $stmt->execute(['novels']);
            }
            if ($stmt->fetchColumn() === false) {
                return [];
            }
            $hasCoverUrl = false;
            if ($driver === 'sqlite') {
                foreach ($pdo->query('PRAGMA table_info(novels)') ?: [] as $column) {
                    if ((string) ($column['name'] ?? '') === 'cover_url') {
                        $hasCoverUrl = true;
                        break;
                    }
                }
            } else {
                $coverStmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
                $coverStmt->execute(['novels', 'cover_url']);
                $hasCoverUrl = (int) $coverStmt->fetchColumn() > 0;
            }
            $coverSelect = $hasCoverUrl ? ', n.cover_url' : ', NULL AS cover_url';
            $rows = $pdo->query('SELECT n.id, n.title, n.description, n.status, n.word_count, n.chapter_count, n.latest_chapter_title, n.latest_chapter_at, n.updated_at, n.published_at' . $coverSelect . ', a.name AS author
                FROM novels n
                LEFT JOIN novel_authors a ON a.id = n.author_id
                WHERE n.visibility = ' . $pdo->quote('public') . ' AND n.chapter_count > 0
                ORDER BY COALESCE(n.latest_chapter_at, n.updated_at, n.published_at) DESC, n.id DESC
                LIMIT 100')->fetchAll();
            $items = [];
            foreach ($rows ?: [] as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $row['id'] = $id;
                $row['formal_novel_id'] = $id;
                $row['job_id'] = 'formal_' . $id;
                $row['author'] = (string) ($row['author'] ?? '佚名');
                $row['category'] = '小说';
                $row['url'] = '/novels/book?job_id=formal_' . rawurlencode((string) $id);
                $row['cover'] = (string) ($row['cover_url'] ?? '');
                $items[] = $row;
            }
            $new = $items;
            usort($new, static fn (array $a, array $b): int => strcmp((string) ($b['published_at'] ?? $b['updated_at'] ?? ''), (string) ($a['published_at'] ?? $a['updated_at'] ?? '')));
            $ranking = $items;
            usort($ranking, static fn (array $a, array $b): int => ((int) ($b['word_count'] ?? 0) <=> (int) ($a['word_count'] ?? 0)) ?: ((int) ($b['chapter_count'] ?? 0) <=> (int) ($a['chapter_count'] ?? 0)));
            $completed = array_values(array_filter($items, static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['completed', 'complete', 'finished', '完结', '完本'], true)));
            return [
                'recommended' => array_slice($ranking, 0, 18),
                'latest' => array_slice($items, 0, 30),
                'new' => array_slice($new, 0, 18),
                'completed' => array_slice($completed, 0, 18),
                'ranking' => array_slice($ranking, 0, 18),
            ];
        } catch (Throwable) {
            return [];
        }
    })();
}
$assetBase = '/content/themes/daiying_novel/assets';
$inlineCss = is_file(__DIR__ . '/../assets/style.css') ? (string) file_get_contents(__DIR__ . '/../assets/style.css') : '';
$settings = $get('theme_settings', $get('settings', []));
$settings = is_array($settings) ? $settings : [];
$setting = static fn (string $key, $default) => $settings[$key] ?? $get('theme.' . $key, $default);
$boolSetting = static fn (string $key, bool $default): bool => filter_var($settings[$key] ?? $get('theme.' . $key, $default), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
$accent = (string) $setting('accent_color', '#b8324a');
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : '#b8324a';
$brandName = (string) $setting('brand_name', $site);
$logoText = mb_substr((string) $setting('brand_logo_text', 'D'), 0, 2);
$logoUrl = (string) $setting('brand_logo_url', '');
$coverMarkup = static function (array $novel) use ($e): string {
    $cover = (string) ($novel['cover'] ?? $novel['cover_url'] ?? '');
    if ($cover !== '') {
        return '<img src="' . $e($cover) . '" alt="">';
    }
    return '<span class="generated-cover"><strong>' . $e(mb_substr((string) ($novel['title'] ?? '小说'), 0, 8)) . '</strong><em>' . $e((string) ($novel['author'] ?? '佚名')) . '</em></span>';
};
$showSearch = $boolSetting('show_search', true);
$showStats = $boolSetting('show_home_stats', true);
$showQuickLinks = $boolSetting('show_quick_links', true);
$totalNovels = 0;
foreach ($sections as $items) {
    $totalNovels += is_array($items) ? count($items) : 0;
}
$sectionCount = static fn (string $key): int => is_array($sections[$key] ?? null) ? count($sections[$key]) : 0;
$sectionLabels = [
    'recommended' => (string) $setting('section_recommended_label', '推荐'),
    'latest' => (string) $setting('section_latest_label', '最新更新'),
    'new' => (string) $setting('section_new_label', '新书'),
    'completed' => (string) $setting('section_completed_label', '完本'),
    'ranking' => (string) $setting('section_ranking_label', '排行'),
];
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($brandName) ?></title>
    <meta name="description" content="<?= $e($get('seo.description', '小说搜索、排行、最新更新和阅读书架')) ?>">
    <link rel="stylesheet" href="<?= $e($assetBase) ?>/style.css">
    <?php if ($inlineCss !== ''): ?><style><?= $inlineCss ?></style><?php endif; ?>
    <style>:root{--accent:<?= $e($accent) ?>;--accent-strong:<?= $e($accent) ?>}</style>
</head>
<body>
<div class="site-shell">
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="/">
            <?php if ($logoUrl !== ''): ?><img class="brand-logo" src="<?= $e($logoUrl) ?>" alt=""><?php else: ?><span class="brand-mark"><?= $e($logoText) ?></span><?php endif; ?>
            <span><?= $e($brandName) ?></span>
        </a>
        <nav class="main-nav" aria-label="小说导航">
            <a href="/novels" aria-current="page"><?= $e($setting('nav_label_library', '书库')) ?></a>
            <a href="/novels?tab=latest"><?= $e($setting('nav_label_latest', '更新')) ?></a>
            <a href="/novels?tab=completed"><?= $e($setting('nav_label_completed', '完本')) ?></a>
            <a href="/novels?tab=ranking"><?= $e($setting('nav_label_ranking', '排行')) ?></a>
            <a href="<?= $e($novelBookshelfUrl()) ?>">书架</a>
        </nav>
        <?php if ($showSearch): ?>
        <form class="search" action="<?= $e($novelSearchUrl()) ?>" method="get">
            <input name="q" type="search" placeholder="<?= $e($setting('search_placeholder', '搜索书名、作者')) ?>">
            <button>搜索</button>
        </form>
        <?php endif; ?>
    </div>
</header>
<main>
    <section class="band lead">
        <div class="lead-copy">
            <p class="eyebrow"><?= $e($setting('home_eyebrow', 'Daiying Novel Library')) ?></p>
            <h1><?= $e($setting('home_title', '小说书库')) ?></h1>
            <p><?= $e($setting('home_subtitle', '按推荐、更新、新书、完本和排行组织内容，适合连续阅读、追更和移动端浏览。')) ?></p>
            <?php if ($showStats): ?>
            <div class="stats">
                <div class="stat"><strong><?= $e((string) $totalNovels) ?></strong><span>当前展示</span></div>
                <div class="stat"><strong><?= $e((string) $sectionCount('latest')) ?></strong><span>最新更新</span></div>
                <div class="stat"><strong><?= $e((string) $sectionCount('completed')) ?></strong><span>完本推荐</span></div>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($showQuickLinks): ?>
        <aside class="side-panel" aria-label="书库入口">
            <h2>快速入口</h2>
            <ul class="side-list">
                <li><strong>正在追更</strong><span><a href="<?= $e($novelBookshelfUrl()) ?>">继续阅读</a></span></li>
                <li><strong>新入库</strong><span>发现新书</span></li>
                <li><strong>TXT</strong><span>缓存导出</span></li>
            </ul>
        </aside>
        <?php endif; ?>
    </section>
    <?php foreach ($sectionLabels as $key => $label): ?>
        <?php $items = is_array($sections[$key] ?? null) ? $sections[$key] : []; ?>
        <section class="band">
            <div class="section-title">
                <div>
                    <h2><?= $e($label) ?></h2>
                    <p><?= $e($key === 'latest' ? '按最近章节更新排序' : '从正式小说内容库读取') ?></p>
                </div>
            </div>
            <?php if ($items === []): ?>
                <div class="empty-state"><?= $e($setting('empty_text', '这里还没有可显示的小说。采集完成并写入正式内容后，会自动出现在这个分区。')) ?></div>
            <?php elseif ($key === 'latest'): ?>
                <table class="update-table">
                    <thead><tr><th>分类</th><th>书名</th><th>最新章节</th><th>作者</th><th>更新时间</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($items, 0, 30) as $novel): ?>
                        <tr>
                            <td><?= $e($novel['category'] ?? $novel['category_name'] ?? '小说') ?></td>
                            <td><a href="<?= $e($novelUrl($novel)) ?>"><?= $e($novel['title'] ?? '') ?></a></td>
                            <td><?= $e($novel['latest_chapter_title'] ?? '暂无章节') ?></td>
                            <td><?= $e($novel['author'] ?? '佚名') ?></td>
                            <td><?= $e($novel['updated_at'] ?? $novel['latest_chapter_at'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="book-grid">
                    <?php foreach ($items as $novel): ?>
                        <article class="book">
                            <a href="<?= $e($novelUrl($novel)) ?>">
                                <?= $coverMarkup($novel) ?>
                                <span class="book-info">
                                    <strong><?= $e($novel['title'] ?? '') ?></strong>
                                    <span><?= $e($novel['author'] ?? '佚名') ?></span>
                                    <em><?= $e($novel['latest_chapter_title'] ?? '暂无章节') ?></em>
                                </span>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</main>
</div>
</body>
</html>
