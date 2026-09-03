<?php

declare(strict_types=1);

use Official\VideoCollector\CategoryMapper;
use Official\VideoCollector\ProviderDetector;
use Official\VideoCollector\ResourceProviderParser;
use Official\VideoCollector\SafeHttpClient;
use Official\VideoCollector\SecurityException;
use Official\VideoCollector\VideoRepository;

require_once __DIR__ . '/../content/plugins/official.video-collector/src/VideoSystem.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$migration1 = require __DIR__ . '/../content/plugins/official.video-collector/migrations/001_video_core_and_collector.php';
$migration2 = require __DIR__ . '/../content/plugins/official.video-collector/migrations/002_video_smart_mode.php';
$migration1['up']($pdo);
$migration2['up']($pdo);
$migration2['up']($pdo);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$http = new SafeHttpClient();
$parser = new ResourceProviderParser();
$mapper = new CategoryMapper();
$detector = new ProviderDetector($parser, $mapper, $http);
$repo = new VideoRepository($pdo, $http, $mapper);

$jsonPayload = (string) file_get_contents(__DIR__ . '/../content/plugins/official.video-collector/fixtures/maccms.json');
$detection = $detector->detect('https://example.com/api.php/provide/vod/?ac=detail', $jsonPayload);
$assert($detection['provider_type'] === 'maccms_json', 'MACCMS JSON provider should be detected.');
$assert((int) $detection['resource_count'] === 1, 'JSON fixture should expose one video.');
$assert(($detection['type_summary']['short_drama'] ?? 0) === 1, 'Short drama category should be auto mapped.');

$xmlPayload = <<<'XML'
<rss>
  <class><ty id="1">纪录片</ty></class>
  <list>
    <video>
      <vod_id>x-9</vod_id>
      <type_id>1</type_id>
      <type_name>纪录片</type_name>
      <vod_name>山河纪实</vod_name>
      <vod_year>2026</vod_year>
      <vod_play_from>dbm3u8</vod_play_from>
      <vod_play_url>第1集$https://example.com/doc/1.m3u8</vod_play_url>
    </video>
  </list>
</rss>
XML;
$xmlDetection = $detector->detect('https://example.com/api.php/provide/vod/?ac=detail', $xmlPayload);
$assert($xmlDetection['provider_type'] === 'maccms_xml', 'MACCMS XML provider should be detected.');
$assert(($xmlDetection['type_summary']['documentary'] ?? 0) === 1, 'Documentary category should be auto mapped.');

$providerId = $repo->saveProvider([
    'name' => 'Example MACCMS',
    'provider_type' => 'maccms_json',
    'api_url' => 'https://example.com/api.php/provide/vod/?ac=detail',
    'enabled' => 1,
    'auto_sync_enabled' => 1,
]);
$repo->recordProviderDetection($providerId, $detection);
$jobId = $repo->createJob($providerId, 'full_collect', $detection['items'], 10);
$run = $repo->runJob($jobId, 10);
$assert($run['status'] === 'completed', 'Initial import job should complete.');
$assert((int) $repo->stats()['videos'] === 1, 'Initial import should create one video.');
$assert((int) $repo->stats()['episodes'] === 2, 'Initial import should create two unique episodes.');
$assert((int) $repo->stats()['play_urls'] === 4, 'Initial import should create four episode play URLs.');

$duplicateJob = $repo->createJob($providerId, 'full_collect', $detection['items'], 10);
$repo->runJob($duplicateJob, 10);
$assert((int) $repo->stats()['videos'] === 1, 'Duplicate import should not create another video.');
$assert((int) $repo->stats()['episodes'] === 2, 'Duplicate import should not create duplicate episodes.');
$assert((int) $repo->stats()['play_urls'] === 4, 'Duplicate import should not create duplicate play URLs.');

$changed = $detection['items'];
$changed[0]['play_groups'][0]['episodes'][1]['url'] = 'https://example.com/a/2-v2.m3u8';
$changedJob = $repo->createJob($providerId, 'incremental', $changed, 10);
$repo->runJob($changedJob, 10);
$assert((int) $repo->stats()['play_urls'] === 4, 'Changed source URL should update, not duplicate, the same episode/source row.');

$incremental = $changed;
$incremental[0]['play_groups'][0]['episodes'][] = ['title' => '第3集', 'url' => 'https://example.com/a/3.m3u8', 'url_type' => 'hls', 'episode_number' => 3];
$incrementalJob = $repo->createJob($providerId, 'incremental', $incremental, 10);
$repo->runJob($incrementalJob, 10);
$assert((int) $repo->stats()['episodes'] === 3, 'Incremental import should append a new episode.');
$assert((int) $repo->stats()['play_urls'] === 5, 'Incremental import should append only the new play URL.');

$malicious = $detection['items'];
$malicious[0]['external_id'] = 'evil-1';
$malicious[0]['title'] = '本地地址测试';
$malicious[0]['play_groups'] = [['play_source_code' => 'evil', 'episodes' => [['title' => '第1集', 'url' => 'http://127.0.0.1/evil.m3u8', 'url_type' => 'hls', 'episode_number' => 1]]]];
$evilResult = $repo->importVideoGraph($providerId, $malicious[0]);
$assert($evilResult['status'] === 'skipped', 'Unsafe local playback URL should be skipped.');

foreach (['ftp://example.com/a.m3u8', 'http://localhost/a.m3u8', 'http://169.254.169.254/latest/meta-data'] as $blockedUrl) {
    try {
        $http->assertSafeUrl($blockedUrl);
        throw new RuntimeException('Unsafe URL was not blocked: ' . $blockedUrl);
    } catch (SecurityException) {
    }
}

$videos = $repo->publicVideos(10);
$assert(count($videos) === 1, 'Frontend video listing should expose imported public videos.');
$episodes = $repo->episodesForVideo((int) $videos[0]['id']);
$assert(count($episodes) === 3, 'Detail page repository query should expose three episodes.');
$episodeId = (int) $episodes[0]['id'];
$pdo->prepare("UPDATE video_episode_play_urls SET health_status = 'failed' WHERE episode_id = ?")->execute([$episodeId]);
$pdo->prepare("UPDATE video_episode_play_urls SET health_status = 'healthy' WHERE episode_id = ? AND id = (SELECT MIN(id) FROM video_episode_play_urls WHERE episode_id = ?)")->execute([$episodeId, $episodeId]);
$playUrls = $repo->playUrlsForEpisode($episodeId);
$assert(($playUrls[0]['health_status'] ?? '') === 'healthy', 'Player should prefer healthy play source rows.');

echo "video_collector_smart_mode: PASS\n";
