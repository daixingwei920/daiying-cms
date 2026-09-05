<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/content/plugins/official.novel-collector/src/NovelSystem.php';

use Official\NovelCollector\ContentQualityAnalyzer;
use Official\NovelCollector\ContentQualityException;
use Official\NovelCollector\HtmlSanitizer;
use Official\NovelCollector\NovelAutoDetector;
use Official\NovelCollector\NovelRepository;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$sanitizer = new HtmlSanitizer();
$quality = new ContentQualityAnalyzer();
$detector = new NovelAutoDetector();

$normalParagraphs = [
    '夜色落在城墙上，巡灯沿着青石路一点点亮起。许临风收好旧剑，听见远处钟声穿过雨幕，像是在提醒他离约定的时辰只剩半刻。',
    '他推门时，案上的信纸还带着未干的墨迹。纸上没有多余寒暄，只写着城南码头、第三盏红灯、不要回头。越是简单的话，越像一枚压在心口的石子。',
    '码头边的船夫披着蓑衣，抬眼看了他一瞬，又很快低下头去。许临风没有问价，只把铜钱放在木桩上，任由小船顺着暗流离岸。',
    '雾气渐浓，水声里夹着细碎脚步。等他终于看见第三盏红灯，才发现灯下站着的人并不是送信人，而是三年前本该死在雪岭的旧友。',
];
$normalClean = $sanitizer->clean(implode("\n", array_map(static fn (string $line): string => '<p>' . $line . '</p>', $normalParagraphs)));
$normalResult = $quality->assertAcceptable($normalClean, [
    'requested_url' => 'https://example.com/book/1.html',
    'final_url' => 'https://example.com/book/1.html',
    'http_status' => 200,
    'content_type' => 'text/html; charset=utf-8',
    'response_length' => 1200,
    'redirect_count' => 0,
], ['title' => '第一章 初见', 'url' => 'https://example.com/book/1.html']);
$assert(($normalResult['quality'] ?? '') === 'ok', 'Normal chapter prose should pass content quality checks.');

$honeypot = str_repeat(
    'zs66★cc今年２２岁，是一个大学阿拉伯语专业的学生，全班一共３０人，另１０个男生，委琐不堪，典型东北大汉。最新网址 bqgme•cc 备用网址 www badsite cc 广告 推广 扫码 APP下载 成人 博彩 联系方式。' . "\n",
    3
);
$honeypotClean = $sanitizer->clean('<div class="content">' . nl2br($honeypot) . '</div>');
$honeypotResult = $quality->analyze($honeypotClean, [
    'requested_url' => 'https://apibi.cc/api/chapter?token=one',
    'final_url' => 'https://apibi.cc/api/chapter?token=one',
    'http_status' => 200,
    'content_type' => 'application/json',
    'response_length' => 1600,
    'redirect_count' => 0,
], ['title' => '第五百九十一章 正文']);
$assert(($honeypotResult['quality'] ?? '') === 'failed', 'Known BQG honeypot promo text should be rejected.');
$assert(in_array('known_honeypot_promo_template', $honeypotResult['reasons'] ?? [], true), 'Honeypot template reason should be reported.');

$threw = false;
try {
    $quality->assertAcceptable($honeypotClean, ['http_status' => 200], ['title' => '第五百九十一章 正文']);
} catch (ContentQualityException $e) {
    $threw = str_contains($e->getMessage(), 'content_quality_failed');
}
$assert($threw, 'Unacceptable chapter content should throw ContentQualityException before save.');

$errorPage = $sanitizer->clean(str_repeat('<p>验证码 访问过于频繁 请登录 点击继续 最新网址 备用网址 广告 推广 扫码 APP下载 安全验证 人机验证 Cloudflare Just a moment</p>', 3));
$errorResult = $quality->analyze($errorPage, [
    'requested_url' => 'https://example.com/book/2.html',
    'final_url' => 'https://example.com/',
    'http_status' => 200,
    'response_length' => 800,
], ['title' => '第二章 渡河']);
$assert(($errorResult['quality'] ?? '') === 'failed', 'HTTP 200 error or verification pages should be rejected.');
$assert(in_array('unexpected_final_url', $errorResult['reasons'] ?? [], true), 'Unexpected landing final URL should be reported.');

$articleHtml = '<main>'
    . '<div class="ads content">' . str_repeat('<a href="https://ad.example.com">广告 推广 APP下载 最新网址</a> ', 80) . '</div>'
    . '<article id="chapter-content">'
    . implode('', array_map(static fn (string $line): string => '<p>' . $line . '</p>', $normalParagraphs))
    . '</article>'
    . '</main>';
$extracted = $detector->extractChapterBody('https://example.com/book/1.html', $articleHtml);
$extractedClean = $sanitizer->clean($extracted);
$assert(str_contains($extractedClean['plaintext'], '许临风收好旧剑'), 'Chapter body selector should prefer article content over ad blocks.');
$assert(!str_contains($extractedClean['plaintext'], 'APP下载'), 'Chapter body selector should not keep the ad block as content.');

$preflight = $detector->preflight([
    ['title' => '第一章 初见', 'url' => 'https://example.com/book/1.html'],
    ['title' => '第二章 重逢', 'url' => 'https://example.com/book/2.html'],
    ['title' => '第三章 归途', 'url' => 'https://example.com/book/3.html'],
], static fn (string $url): array => [
    'response' => ['requested_url' => $url, 'final_url' => $url, 'http_status' => 200, 'response_length' => 1200],
    'clean' => $normalClean,
]);
$assert(($preflight['pass'] ?? true) === false, 'Preflight should fail when sampled chapters have duplicate content fingerprints.');
$assert(in_array('Duplicate chapter body detected.', $preflight['errors'] ?? [], true), 'Preflight should report duplicate chapter body.');

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE novel_chapters (id INTEGER PRIMARY KEY AUTOINCREMENT, novel_id INTEGER, sort_order INTEGER, source_chapter_id TEXT, content_hash TEXT)');
$hash = ContentQualityAnalyzer::fingerprint($normalClean['plaintext']);
$pdo->prepare('INSERT INTO novel_chapters (novel_id, sort_order, source_chapter_id, content_hash) VALUES (?,?,?,?)')->execute([9, 1, '9:1', $hash]);
$repo = new NovelRepository($pdo);
$assert($repo->hasDuplicateChapterContent(9, $hash, 2, '9:2') === true, 'Repository should find same content hash on a different chapter.');
$assert($repo->hasDuplicateChapterContent(9, $hash, 1, '9:1') === false, 'Repository should allow updating the same chapter hash.');

echo "novel_collector_content_quality_guard: PASS\n";
