<?php

declare(strict_types=1);

namespace Cms\Core\Seo\Historical;

use Cms\Core\Ai\AiException;
use Cms\Core\Ai\AiRequest;
use Cms\Core\Ai\AiService;
use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentRevisionRepository;
use PDO;
use Throwable;

final class HistoricalArticleSeoBackfillService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ContentRepository $contents,
        private readonly Settings $settings,
        private readonly ?AiService $ai = null,
    ) {
    }

    /** @return array<string,int> */
    public function auditStats(): array
    {
        $articles = $this->publishedArticles();
        $bindings = $this->contents->targetKeywordBindings();
        $articleBindingIds = [];
        foreach ($bindings as $binding) {
            if (str_starts_with((string) ($binding['source_type'] ?? ''), 'content:article')) {
                $articleBindingIds[(int) ($binding['source_id'] ?? 0)] = true;
            }
        }

        $stats = [
            'article_total' => count($articles),
            'seo_title' => 0,
            'seo_description' => 0,
            'target_keywords' => 0,
            'keyword_center_articles' => count($articleBindingIds),
            'no_seo' => 0,
            'incomplete_seo' => 0,
            'short_articles' => 0,
            'low_quality' => 0,
            'possible_duplicates' => 0,
            'quality_a' => 0,
            'quality_b' => 0,
            'quality_c' => 0,
            'quality_d' => 0,
        ];

        $fingerprints = [];
        foreach ($articles as $article) {
            $meta = $this->decodeJson((string) ($article['meta_json'] ?? '{}'));
            $blocks = $this->decodeJson((string) ($article['blocks_json'] ?? '[]'));
            $quality = $this->analyzeQuality($article, $blocks, $fingerprints);
            $fingerprints[] = $quality['fingerprint'];
            $hasTitle = trim((string) ($meta['seo_title'] ?? '')) !== '';
            $hasDescription = trim((string) ($meta['seo_description'] ?? '')) !== '';
            $hasTarget = trim((string) ($meta['target_keywords'] ?? '')) !== '';
            $hasKeywords = trim((string) ($meta['seo_keywords'] ?? '')) !== '';
            $hasCanonical = trim((string) ($meta['canonical_url'] ?? '')) !== '';
            $complete = $hasTitle && $hasDescription && $hasTarget && $hasCanonical;

            $stats['seo_title'] += $hasTitle ? 1 : 0;
            $stats['seo_description'] += $hasDescription ? 1 : 0;
            $stats['target_keywords'] += $hasTarget ? 1 : 0;
            $stats['no_seo'] += (!$hasTitle && !$hasDescription && !$hasTarget && !$hasKeywords && !$hasCanonical) ? 1 : 0;
            $stats['incomplete_seo'] += $complete ? 0 : 1;
            $stats['short_articles'] += ((int) $quality['word_count'] < 500) ? 1 : 0;
            $stats['low_quality'] += in_array($quality['quality_grade'], ['C', 'D'], true) ? 1 : 0;
            $stats['possible_duplicates'] += ((int) $quality['possible_duplicate']) === 1 ? 1 : 0;
            $stats['quality_' . strtolower((string) $quality['quality_grade'])]++;
        }

        return $stats;
    }

    /** @return array{processed:int,failed:int} */
    public function scan(int $limit = 100): array
    {
        $articles = array_slice($this->publishedArticles(), 0, max(1, min(500, $limit)));
        $fingerprints = [];
        $processed = 0;
        $failed = 0;
        foreach ($articles as $article) {
            try {
                $blocks = $this->decodeJson((string) ($article['blocks_json'] ?? '[]'));
                $meta = $this->decodeJson((string) ($article['meta_json'] ?? '{}'));
                $quality = $this->analyzeQuality($article, $blocks, $fingerprints);
                $fingerprints[] = $quality['fingerprint'];
                $seoStatus = $this->seoStatus($meta);
                $recommendation = $this->recommendation($quality, $seoStatus);
                $now = gmdate('c');
                $this->upsertJob([
                    'content_id' => (int) $article['id'],
                    'quality_grade' => (string) $quality['quality_grade'],
                    'word_count' => (int) $quality['word_count'],
                    'paragraph_count' => (int) $quality['paragraph_count'],
                    'heading_count' => (int) $quality['heading_count'],
                    'has_image' => (int) $quality['has_image'],
                    'possible_duplicate' => (int) $quality['possible_duplicate'],
                    'early_batch_candidate' => (int) $quality['early_batch_candidate'],
                    'seo_status' => $seoStatus,
                    'recommendation' => $recommendation,
                    'original_meta_hash' => hash('sha256', (string) ($article['meta_json'] ?? '')),
                    'original_blocks_hash' => hash('sha256', (string) ($article['blocks_json'] ?? '')),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->log((int) $article['id'], 'scan', 'ok', '历史文章质量与 SEO 状态已扫描。');
                $processed++;
            } catch (Throwable $exception) {
                $failed++;
                $this->log((int) ($article['id'] ?? 0), 'scan', 'failed', $exception->getMessage());
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    /** @return array{processed:int,failed:int} */
    public function generateBatch(int $limit = 10, bool $regenerate = false): array
    {
        $limit = max(1, min(20, $limit));
        $where = $regenerate ? '1 = 1' : "(j.status IN ('scanned','failed') OR j.proposed_meta_json IS NULL)";
        $stmt = $this->pdo->query("SELECT c.*, j.id AS job_id FROM cms_historical_article_seo_jobs j JOIN cms_contents c ON c.id = j.content_id WHERE c.status = 'published' AND c.content_type = 'article' AND " . $where . ' ORDER BY j.id ASC LIMIT ' . $limit);
        $processed = 0;
        $failed = 0;
        foreach ($stmt->fetchAll() as $article) {
            try {
                $this->generateForArticle($article, $regenerate);
                $processed++;
            } catch (Throwable $exception) {
                $failed++;
                $this->markFailed((int) $article['id'], $exception->getMessage());
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    public function generateForArticleId(int $contentId, bool $regenerate = false): void
    {
        $article = $this->findArticle($contentId);
        if ($article === null) {
            throw new \RuntimeException('文章不存在。');
        }
        $this->generateForArticle($article, $regenerate);
    }

    /** @return array{processed:int,failed:int} */
    public function generateAiBatch(int $limit = 5, bool $regenerate = false): array
    {
        $limit = max(1, min(10, $limit));
        $where = $regenerate
            ? "j.quality_grade IN ('B','C')"
            : "j.quality_grade IN ('B','C') AND (j.suggestion_source <> 'ai' OR j.proposed_blocks_json IS NULL)";
        $stmt = $this->pdo->query("SELECT c.*, j.id AS job_id, j.quality_grade FROM cms_historical_article_seo_jobs j JOIN cms_contents c ON c.id = j.content_id WHERE c.status = 'published' AND c.content_type = 'article' AND " . $where . ' ORDER BY j.quality_grade DESC, j.id ASC LIMIT ' . $limit);
        $processed = 0;
        $failed = 0;
        foreach ($stmt->fetchAll() as $article) {
            try {
                $this->generateAiForArticle($article, $regenerate);
                $processed++;
            } catch (Throwable $exception) {
                $failed++;
                $this->markFailed((int) $article['id'], $exception->getMessage());
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    public function generateAiForArticleId(int $contentId, bool $regenerate = false): void
    {
        $article = $this->findArticle($contentId);
        if ($article === null) {
            throw new \RuntimeException('文章不存在。');
        }
        $this->generateAiForArticle($article, $regenerate);
    }

    public function applySeo(int $contentId, bool $regenerate = false): void
    {
        $article = $this->findArticle($contentId);
        if ($article === null) {
            throw new \RuntimeException('文章不存在。');
        }
        $job = $this->findJob($contentId);
        if ($job === null || trim((string) ($job['proposed_meta_json'] ?? '')) === '') {
            $this->generateForArticle($article, $regenerate);
            $job = $this->findJob($contentId);
        }
        $suggested = $this->decodeJson((string) ($job['proposed_meta_json'] ?? '{}'));
        $currentMeta = $this->decodeJson((string) ($article['meta_json'] ?? '{}'));
        $newMeta = $this->mergeMissingMeta($currentMeta, $suggested, $regenerate);
        if ($newMeta === $currentMeta) {
            $this->log($contentId, 'apply_seo', 'skipped', '已有人工 SEO 数据，未覆盖。');
            return;
        }

        (new ContentRevisionRepository($this->pdo))->recordFromContent($article, null, 'before_historical_seo_backfill');
        $stmt = $this->pdo->prepare('UPDATE cms_contents SET meta_json = :meta_json, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':id' => $contentId,
            ':meta_json' => json_encode($newMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':updated_at' => gmdate('c'),
        ]);

        $primary = $this->firstKeyword((string) ($newMeta['target_keywords'] ?? ''));
        if ($primary !== '') {
            $this->contents->saveSeoKeyword($primary, $this->articlePath($article), 'active', 'content', 'Historical article SEO backfill.');
        }
        $now = gmdate('c');
        $this->pdo->prepare('UPDATE cms_historical_article_seo_jobs SET status = "applied", applied_at = :applied_at, updated_at = :updated_at WHERE content_id = :content_id')
            ->execute([':content_id' => $contentId, ':applied_at' => $now, ':updated_at' => $now]);
        $this->log($contentId, 'apply_seo', 'ok', '已补全缺失 SEO 字段并接入关键词中心。');
    }

    /** @return list<array<string,mixed>> */
    public function rows(array $filters = [], int $limit = 100): array
    {
        $this->ensureJobsExist();
        $where = ["c.status = 'published'", "c.content_type = 'article'"];
        $params = [];
        if (($filters['quality'] ?? '') !== '' && ($filters['quality'] ?? 'all') !== 'all') {
            $where[] = 'j.quality_grade = :quality';
            $params[':quality'] = (string) $filters['quality'];
        }
        if (($filters['seo'] ?? '') === 'incomplete') {
            $where[] = "j.seo_status <> 'complete'";
        }
        if (($filters['seo'] ?? '') === 'missing') {
            $where[] = "j.seo_status = 'missing'";
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = 'c.title LIKE :q';
            $params[':q'] = '%' . (string) $filters['q'] . '%';
        }
        $stmt = $this->pdo->prepare('SELECT c.id, c.title, c.slug, c.updated_at, c.meta_json, j.* FROM cms_historical_article_seo_jobs j JOIN cms_contents c ON c.id = j.content_id WHERE ' . implode(' AND ', $where) . ' ORDER BY j.quality_grade DESC, c.id ASC LIMIT ' . max(1, min(300, $limit)));
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string,int> */
    public function jobStats(): array
    {
        $this->ensureJobsExist();
        $stats = $this->auditStats();
        $stats += [
            'jobs_scanned' => 0,
            'jobs_generated' => 0,
            'jobs_applied' => 0,
            'jobs_failed' => 0,
            'seo_only' => 0,
            'expand' => 0,
            'rewrite' => 0,
            'review' => 0,
        ];
        foreach ($this->pdo->query('SELECT status, recommendation, COUNT(*) AS total FROM cms_historical_article_seo_jobs GROUP BY status, recommendation')->fetchAll() as $row) {
            $status = (string) ($row['status'] ?? '');
            $recommendation = (string) ($row['recommendation'] ?? '');
            $total = (int) ($row['total'] ?? 0);
            if (isset($stats['jobs_' . $status])) {
                $stats['jobs_' . $status] += $total;
            }
            if (isset($stats[$recommendation])) {
                $stats[$recommendation] += $total;
            }
        }

        return $stats;
    }

    private function generateForArticle(array $article, bool $regenerate): void
    {
        $meta = $this->decodeJson((string) ($article['meta_json'] ?? '{}'));
        $blocks = $this->decodeJson((string) ($article['blocks_json'] ?? '[]'));
        $suggested = $this->suggestMeta($article, $blocks);
        $proposed = $this->mergeMissingMeta($meta, $suggested, $regenerate);
        $quality = $this->analyzeQuality($article, $blocks, []);
        $draftBlocks = in_array($quality['quality_grade'], ['B', 'C'], true) ? $this->suggestExpandedBlocks($article, $blocks, $suggested) : null;
        $now = gmdate('c');
        $stmt = $this->pdo->prepare('UPDATE cms_historical_article_seo_jobs SET proposed_meta_json = :proposed_meta_json, proposed_blocks_json = :proposed_blocks_json, suggestion_source = "rules", search_intent = NULL, primary_keyword = :primary_keyword, auxiliary_keywords = :auxiliary_keywords, ai_provider = "", ai_model = "", ai_request_id = "", ai_usage_json = NULL, ai_change_summary = NULL, status = "generated", generated_at = :generated_at, updated_at = :updated_at, error_summary = NULL WHERE content_id = :content_id');
        $stmt->execute([
            ':content_id' => (int) $article['id'],
            ':proposed_meta_json' => json_encode($proposed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':proposed_blocks_json' => $draftBlocks === null ? null : json_encode($draftBlocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':primary_keyword' => $this->firstKeyword((string) ($proposed['target_keywords'] ?? '')),
            ':auxiliary_keywords' => (string) ($proposed['seo_keywords'] ?? ''),
            ':generated_at' => $now,
            ':updated_at' => $now,
        ]);
        $this->log((int) $article['id'], 'generate', 'ok', $draftBlocks === null ? '已生成缺失 SEO 建议。' : '已生成 SEO 建议和待审核正文优化草稿。');
    }

    private function generateAiForArticle(array $article, bool $regenerate): void
    {
        if ($this->ai === null || !$this->ai->isEnabled()) {
            throw new \RuntimeException('站点 AI Provider 未启用或未配置，无法生成 AI 优化草稿。');
        }
        $job = $this->findJob((int) $article['id']);
        $grade = (string) ($job['quality_grade'] ?? 'C');
        if (!in_array($grade, ['B', 'C'], true)) {
            throw new \RuntimeException('AI 正文优化仅用于 B/C 级历史文章。');
        }
        $meta = $this->decodeJson((string) ($article['meta_json'] ?? '{}'));
        $blocks = $this->decodeJson((string) ($article['blocks_json'] ?? '[]'));
        $response = $this->ai->request(AiRequest::chat($this->aiMessages($article, $blocks, $grade), [
            'operation' => 'historical_content_optimization',
            'plugin_id' => 'core.seo.historical',
            'max_tokens' => 4096,
            'temperature' => 0.35,
        ]));
        $proposal = $this->decodeAiProposal($response->content);
        $auxiliary = $this->stringList($proposal['auxiliary_keywords'] ?? []);
        $target = array_values(array_unique(array_filter(array_merge([(string) ($proposal['primary_keyword'] ?? '')], $auxiliary))));
        $suggestedMeta = [
            'seo_title' => $this->limit((string) ($proposal['seo_title'] ?? ''), 80),
            'seo_description' => $this->limit((string) ($proposal['seo_description'] ?? ''), 160),
            'seo_keywords' => implode(', ', $auxiliary),
            'target_keywords' => implode(', ', $target),
            'canonical_url' => rtrim($this->siteUrl(), '/') . $this->articlePath($article),
        ];
        $draftBlocks = $this->markdownToBlocks((string) ($proposal['body_markdown'] ?? ''));
        if ($draftBlocks === []) {
            throw new \RuntimeException('AI 未返回可审核正文草稿。');
        }
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE cms_historical_article_seo_jobs
             SET proposed_meta_json = :proposed_meta_json,
                 proposed_blocks_json = :proposed_blocks_json,
                 suggestion_source = "ai",
                 search_intent = :search_intent,
                 primary_keyword = :primary_keyword,
                 auxiliary_keywords = :auxiliary_keywords,
                 ai_provider = :ai_provider,
                 ai_model = :ai_model,
                 ai_request_id = :ai_request_id,
                 ai_usage_json = :ai_usage_json,
                 ai_change_summary = :ai_change_summary,
                 status = "generated",
                 generated_at = :generated_at,
                 updated_at = :updated_at,
                 error_summary = NULL
             WHERE content_id = :content_id'
        );
        $stmt->execute([
            ':content_id' => (int) $article['id'],
            ':proposed_meta_json' => json_encode($suggestedMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':proposed_blocks_json' => json_encode($draftBlocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':search_intent' => $this->limit((string) ($proposal['search_intent'] ?? ''), 1000),
            ':primary_keyword' => $this->limit((string) ($proposal['primary_keyword'] ?? ''), 191),
            ':auxiliary_keywords' => implode(', ', $auxiliary),
            ':ai_provider' => $response->provider,
            ':ai_model' => $response->model,
            ':ai_request_id' => $response->requestId,
            ':ai_usage_json' => json_encode($response->usage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':ai_change_summary' => $this->limit((string) ($proposal['change_summary'] ?? ''), 2000),
            ':generated_at' => $now,
            ':updated_at' => $now,
        ]);
        $this->log((int) $article['id'], 'generate_ai', 'ok', '已通过统一 AI Provider 生成待审核正文优化草稿。');
    }

    /** @return list<array<string,mixed>> */
    private function publishedArticles(): array
    {
        return $this->pdo->query("SELECT * FROM cms_contents WHERE status = 'published' AND content_type = 'article' ORDER BY id ASC")->fetchAll();
    }

    private function ensureJobsExist(): void
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM cms_historical_article_seo_jobs')->fetchColumn();
        if ($count === 0) {
            $this->scan(500);
        }
    }

    /** @param array<string,mixed> $data */
    private function upsertJob(array $data): void
    {
        $existing = $this->pdo->prepare('SELECT id FROM cms_historical_article_seo_jobs WHERE content_id = :content_id LIMIT 1');
        $existing->execute([':content_id' => (int) $data['content_id']]);
        $id = (int) $existing->fetchColumn();
        if ($id > 0) {
            $stmt = $this->pdo->prepare(
                'UPDATE cms_historical_article_seo_jobs SET
                    quality_grade = :quality_grade,
                    word_count = :word_count,
                    paragraph_count = :paragraph_count,
                    heading_count = :heading_count,
                    has_image = :has_image,
                    possible_duplicate = :possible_duplicate,
                    early_batch_candidate = :early_batch_candidate,
                    seo_status = :seo_status,
                    recommendation = :recommendation,
                    original_meta_hash = :original_meta_hash,
                    original_blocks_hash = :original_blocks_hash,
                    updated_at = :updated_at
                 WHERE id = :id'
            );
            $data['id'] = $id;
            $stmt->execute($this->jobParams($data, true));
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_historical_article_seo_jobs
                (content_id, quality_grade, word_count, paragraph_count, heading_count, has_image, possible_duplicate, early_batch_candidate, seo_status, recommendation, original_meta_hash, original_blocks_hash, status, created_at, updated_at)
             VALUES
                (:content_id, :quality_grade, :word_count, :paragraph_count, :heading_count, :has_image, :possible_duplicate, :early_batch_candidate, :seo_status, :recommendation, :original_meta_hash, :original_blocks_hash, "scanned", :created_at, :updated_at)'
        );
        $stmt->execute($this->jobParams($data, false));
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function jobParams(array $data, bool $includeId): array
    {
        $params = [
            ':content_id' => (int) $data['content_id'],
            ':quality_grade' => (string) $data['quality_grade'],
            ':word_count' => (int) $data['word_count'],
            ':paragraph_count' => (int) $data['paragraph_count'],
            ':heading_count' => (int) $data['heading_count'],
            ':has_image' => (int) $data['has_image'],
            ':possible_duplicate' => (int) $data['possible_duplicate'],
            ':early_batch_candidate' => (int) $data['early_batch_candidate'],
            ':seo_status' => (string) $data['seo_status'],
            ':recommendation' => (string) $data['recommendation'],
            ':original_meta_hash' => (string) $data['original_meta_hash'],
            ':original_blocks_hash' => (string) $data['original_blocks_hash'],
            ':updated_at' => (string) $data['updated_at'],
        ];
        if (!$includeId) {
            $params[':created_at'] = (string) $data['created_at'];
        } else {
            $params[':id'] = (int) $data['id'];
        }

        return $params;
    }

    private function findArticle(int $contentId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM cms_contents WHERE id = :id AND content_type = 'article' LIMIT 1");
        $stmt->execute([':id' => $contentId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function findJob(int $contentId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_historical_article_seo_jobs WHERE content_id = :content_id LIMIT 1');
        $stmt->execute([':content_id' => $contentId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $article @param list<array<string,mixed>> $blocks @param list<string> $knownFingerprints @return array<string,mixed> */
    private function analyzeQuality(array $article, array $blocks, array $knownFingerprints): array
    {
        $text = $this->plainText($blocks);
        $wordCount = $this->wordCount($text);
        $paragraphs = 0;
        $headings = 0;
        $hasImage = 0;
        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            $paragraphs += $type === 'paragraph' ? 1 : 0;
            $headings += $type === 'heading' ? 1 : 0;
            $hasImage = in_array($type, ['image', 'gallery'], true) ? 1 : $hasImage;
        }
        $fingerprint = $this->fingerprint($text);
        $duplicate = $fingerprint !== '' && in_array($fingerprint, $knownFingerprints, true);
        $earlyBatch = $wordCount < 180 && $headings >= 4 && $paragraphs <= 3;
        $grade = 'A';
        if ($duplicate || $wordCount < 80 || trim($text) === '') {
            $grade = 'D';
        } elseif ($wordCount < 260 || $earlyBatch) {
            $grade = 'C';
        } elseif ($wordCount < 700 || $paragraphs < 4 || $hasImage === 0) {
            $grade = 'B';
        }

        return [
            'quality_grade' => $grade,
            'word_count' => $wordCount,
            'paragraph_count' => $paragraphs,
            'heading_count' => $headings,
            'has_image' => $hasImage,
            'possible_duplicate' => $duplicate ? 1 : 0,
            'early_batch_candidate' => $earlyBatch ? 1 : 0,
            'fingerprint' => $fingerprint,
        ];
    }

    /** @param array<string,mixed> $article @param list<array<string,mixed>> $blocks @return array<string,string> */
    private function suggestMeta(array $article, array $blocks): array
    {
        $title = trim((string) ($article['title'] ?? ''));
        $text = $this->plainText($blocks);
        $keywords = $this->suggestKeywords($title, $text);
        $primary = $keywords[0] ?? $this->cleanKeyword($title);
        $description = $this->description($title, $text, $primary);

        return [
            'seo_title' => $this->limit($title . ' | Daiying CMS 教程', 80),
            'seo_description' => $description,
            'seo_keywords' => implode(', ', array_slice($keywords, 0, 8)),
            'target_keywords' => implode(', ', array_slice($keywords, 0, 4)),
            'canonical_url' => rtrim($this->siteUrl(), '/') . $this->articlePath($article),
        ];
    }

    /** @param array<string,mixed> $article @param list<array<string,mixed>> $blocks @param array<string,string> $suggested @return list<array<string,mixed>> */
    private function suggestExpandedBlocks(array $article, array $blocks, array $suggested): array
    {
        $title = (string) ($article['title'] ?? '');
        $primary = $this->firstKeyword((string) ($suggested['target_keywords'] ?? ''));
        $draft = $blocks;
        $draft[] = ['type' => 'heading', 'data' => ['level' => 2, 'text' => '适用场景']];
        $draft[] = ['type' => 'paragraph', 'data' => ['text' => $title . ' 适合正在评估 Daiying CMS、PHP 建站系统或自建网站方案的管理员。优化时应补充真实操作步骤、注意事项和截图，避免只堆砌关键词。']];
        $draft[] = ['type' => 'heading', 'data' => ['level' => 2, 'text' => '操作建议']];
        $draft[] = ['type' => 'paragraph', 'data' => ['text' => '建议围绕“' . $primary . '”补充准备条件、后台路径、关键配置项、验证方法和常见错误。发布前需要人工审核，确认内容与当前 Daiying CMS 版本一致。']];

        return $draft;
    }

    /** @param array<string,mixed> $article @param list<array<string,mixed>> $blocks @return list<array{role:string,content:string}> */
    private function aiMessages(array $article, array $blocks, string $grade): array
    {
        $instructions = [
            '你是 Daiying CMS 官方历史内容优化助手。',
            '只生成待审核草稿，不发布、不删除、不改 URL。',
            '必须基于给定产品上下文和原文，不得编造不存在的功能、价格、版本、客户案例、统计数据。',
            'B 级：适度扩写；C 级：判断是否仍值得独立存在，再重设计结构并重点重写/扩写。',
            '必须保留原文章主题，避免把短水文变成长水文。',
            'SEO 必须在优化正文之后生成，并与最终正文一致。',
            '只输出 JSON，字段为 search_intent, primary_keyword, auxiliary_keywords, seo_title, seo_description, body_markdown, change_summary。',
        ];

        return [
            ['role' => 'system', 'content' => implode("\n", $instructions)],
            ['role' => 'user', 'content' => json_encode([
                'site_positioning' => 'Daiying CMS / 戴影 CMS 官方中文站，面向 PHP CMS、自建网站、插件、主题、SEO、支付和站点运营管理员。',
                'trusted_product_context' => $this->productContext(),
                'high_quality_site_article_context' => $this->highQualityArticleContext(),
                'quality_grade' => $grade,
                'article' => [
                    'id' => (int) ($article['id'] ?? 0),
                    'title' => (string) ($article['title'] ?? ''),
                    'slug' => (string) ($article['slug'] ?? ''),
                    'url' => $this->articlePath($article),
                    'current_body' => $this->plainText($blocks),
                ],
                'output_rules' => [
                    'body_markdown 使用 H2/H3 标题，不要输出 H1。',
                    '不要声称 Daiying CMS 支持未在上下文中出现的能力。',
                    '不确定的信息要写成需要管理员确认，不能当事实。',
                    '不要关键词堆砌。',
                    '内容完整优先，不设固定字数。',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ];
    }

    /** @return array<string,mixed> */
    private function decodeAiProposal(string $content): array
    {
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
            $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new AiException('AI 返回内容不是合法 JSON。', 'invalid_json');
        }
        foreach (['search_intent', 'primary_keyword', 'seo_title', 'seo_description', 'body_markdown'] as $field) {
            if (trim((string) ($decoded[$field] ?? '')) === '') {
                throw new AiException('AI 返回缺少字段：' . $field, 'invalid_json');
            }
        }

        return $decoded;
    }

    /** @return list<array<string,mixed>> */
    private function markdownToBlocks(string $markdown): array
    {
        $blocks = [];
        $paragraph = [];
        $flush = static function () use (&$paragraph, &$blocks): void {
            $text = trim(implode(' ', $paragraph));
            if ($text !== '') {
                $blocks[] = ['type' => 'paragraph', 'data' => ['text' => $text]];
            }
            $paragraph = [];
        };
        foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                $flush();
                continue;
            }
            if (preg_match('/^(#{2,3})\s+(.+)$/u', $line, $m) === 1) {
                $flush();
                $blocks[] = ['type' => 'heading', 'data' => ['level' => strlen($m[1]), 'text' => trim($m[2])]];
                continue;
            }
            $paragraph[] = preg_replace('/^[-*]\s+/u', '', $line) ?? $line;
        }
        $flush();

        return $blocks;
    }

    /** @param array<string,mixed> $current @param array<string,string> $suggested @return array<string,mixed> */
    private function mergeMissingMeta(array $current, array $suggested, bool $regenerate): array
    {
        foreach (['seo_title', 'seo_description', 'seo_keywords', 'target_keywords', 'canonical_url'] as $field) {
            if ($regenerate || trim((string) ($current[$field] ?? '')) === '') {
                $current[$field] = $suggested[$field] ?? '';
            }
        }
        if (!array_key_exists('robots_index', $current)) {
            $current['robots_index'] = true;
        }
        if (!array_key_exists('robots_follow', $current)) {
            $current['robots_follow'] = true;
        }

        return $current;
    }

    /** @return list<string> */
    private function suggestKeywords(string $title, string $text): array
    {
        $base = [];
        $source = $title . ' ' . $text;
        if (preg_match('/安装|部署|配置/u', $source) === 1) {
            $base[] = '戴影 CMS 安装';
            $base[] = 'Daiying CMS 配置教程';
        }
        if (preg_match('/支付|Stripe|PayPal|收款/u', $source) === 1) {
            $base[] = 'Daiying CMS 支付插件';
        }
        if (preg_match('/主题|模板|外观/u', $source) === 1) {
            $base[] = 'Daiying CMS 主题';
        }
        if (preg_match('/插件|扩展|模块/u', $source) === 1) {
            $base[] = 'Daiying CMS 插件';
        }
        if (preg_match('/WordPress|替代/u', $source) === 1) {
            $base[] = 'WordPress 替代';
        }
        $cleanTitle = $this->cleanKeyword($title);
        if ($cleanTitle !== '') {
            $base[] = str_contains($cleanTitle, 'Daiying') || str_contains($cleanTitle, '戴影') ? $cleanTitle : 'Daiying CMS ' . $cleanTitle;
        }
        $base[] = 'Daiying CMS';
        $base[] = '戴影 CMS';
        $base[] = 'PHP CMS';
        $base[] = '开源 CMS';

        return array_values(array_unique(array_filter(array_map(fn (string $keyword): string => $this->limit($keyword, 80), $base))));
    }

    private function seoStatus(array $meta): string
    {
        $fields = ['seo_title', 'seo_description', 'target_keywords', 'canonical_url'];
        $filled = 0;
        foreach ($fields as $field) {
            $filled += trim((string) ($meta[$field] ?? '')) !== '' ? 1 : 0;
        }
        if ($filled === 0 && trim((string) ($meta['seo_keywords'] ?? '')) === '') {
            return 'missing';
        }

        return $filled === count($fields) ? 'complete' : 'incomplete';
    }

    /** @param array<string,mixed> $quality */
    private function recommendation(array $quality, string $seoStatus): string
    {
        if ((int) $quality['possible_duplicate'] === 1 || $quality['quality_grade'] === 'D') {
            return 'review';
        }
        if ($quality['quality_grade'] === 'C') {
            return 'rewrite';
        }
        if ($quality['quality_grade'] === 'B') {
            return 'expand';
        }

        return $seoStatus === 'complete' ? 'seo_only' : 'seo_only';
    }

    /** @param list<array<string,mixed>> $blocks */
    private function plainText(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            $data = (array) ($block['data'] ?? []);
            foreach (['text', 'caption', 'html', 'content'] as $key) {
                if (isset($data[$key]) && is_scalar($data[$key])) {
                    $parts[] = strip_tags((string) $data[$key]);
                }
            }
        }

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
    }

    private function wordCount(string $text): int
    {
        preg_match_all('/[\p{Han}]|[A-Za-z0-9]+/u', $text, $matches);

        return count($matches[0] ?? []);
    }

    private function fingerprint(string $text): string
    {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = preg_replace('/\s+/u', '', $lower) ?? '';
        if (function_exists('mb_substr')) {
            $text = mb_substr($text, 0, 300, 'UTF-8');
        } else {
            $text = substr($text, 0, 300);
        }

        return $text === '' ? '' : hash('sha256', $text);
    }

    private function description(string $title, string $text, string $primary): string
    {
        $seed = trim($text) !== '' ? $text : $title;
        $seed = $this->limit($seed, 90);
        $description = $primary . '指南：' . $seed . '，帮助站长理解适用场景、配置要点和上线验证方法。';

        return $this->limit($description, 160);
    }

    private function firstKeyword(string $keywords): string
    {
        $parts = preg_split('/[,，\n\r]+/u', $keywords) ?: [];

        return $this->cleanKeyword((string) ($parts[0] ?? ''));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $parts = preg_split('/[,，\n\r]+/u', $value) ?: [];
        } elseif (is_array($value)) {
            $parts = $value;
        } else {
            $parts = [];
        }
        $items = [];
        foreach ($parts as $part) {
            $keyword = $this->cleanKeyword((string) $part);
            if ($keyword === '') {
                continue;
            }
            $items[$keyword] = $keyword;
            if (count($items) >= 12) {
                break;
            }
        }

        return array_values($items);
    }

    private function cleanKeyword(string $keyword): string
    {
        $keyword = trim(preg_replace('/\s+/u', ' ', strip_tags($keyword)) ?? $keyword);

        return $this->limit($keyword, 80);
    }

    private function limit(string $value, int $length): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? $value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }

        return substr($value, 0, $length);
    }

    /** @return array<string|int,mixed> */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $article */
    private function articlePath(array $article): string
    {
        return '/articles/' . ltrim((string) ($article['slug'] ?? ''), '/');
    }

    private function siteUrl(): string
    {
        $url = trim((string) $this->settings->get('site.url', ''));
        if ($url === '') {
            $url = 'https://www.daiyingcms.com';
        }

        return $url;
    }

    /** @return array<string,mixed> */
    private function productContext(): array
    {
        return [
            'product' => 'Daiying CMS / 戴影 CMS',
            'current_core_version' => (string) $this->settings->get('app.version', ''),
            'known_capabilities' => [
                '内容管理：文章、页面、分类、标签、媒体库、评论、导航菜单。',
                'SEO：关键词中心、target keywords、canonical、robots、sitemap、搜索表现数据、Google Search Console 接入、百度 URL 提交插件桥接。',
                'AI：统一 AI Provider，支持 OpenAI-compatible、Gemini、OpenClaw 等适配器，由管理员在后台配置。',
                '扩展：插件管理、主题管理、官方插件市场/本地安装能力。',
                '商业：发卡管理、支付管理、付费内容/下载等 Commerce 能力。',
                '更新：官方更新中心、签名校验、恢复点、健康检查。',
            ],
            'safety_rules' => [
                '没有上下文证据时，不要声称存在某个插件、主题、价格、客户案例或第三方集成。',
                '可以描述已知后台能力，但需要避免夸大。',
            ],
        ];
    }

    /** @return list<array<string,string>> */
    private function highQualityArticleContext(): array
    {
        $items = [];
        foreach ($this->publishedArticles() as $article) {
            $blocks = $this->decodeJson((string) ($article['blocks_json'] ?? '[]'));
            $quality = $this->analyzeQuality($article, $blocks, []);
            if ((string) $quality['quality_grade'] !== 'A') {
                continue;
            }
            $items[] = [
                'title' => (string) ($article['title'] ?? ''),
                'url' => $this->articlePath($article),
                'excerpt' => $this->limit($this->plainText($blocks), 260),
            ];
            if (count($items) >= 5) {
                break;
            }
        }

        return $items;
    }

    private function markFailed(int $contentId, string $message): void
    {
        $this->pdo->prepare('UPDATE cms_historical_article_seo_jobs SET status = "failed", error_summary = :error, updated_at = :updated_at WHERE content_id = :content_id')
            ->execute([':content_id' => $contentId, ':error' => $this->limit($message, 300), ':updated_at' => gmdate('c')]);
        $this->log($contentId, 'generate', 'failed', $message);
    }

    private function log(int $contentId, string $action, string $status, string $message): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO cms_historical_article_seo_logs (content_id, action, status, message, created_at) VALUES (:content_id, :action, :status, :message, :created_at)');
        $stmt->execute([
            ':content_id' => $contentId,
            ':action' => $action,
            ':status' => $status,
            ':message' => $this->limit($message, 500),
            ':created_at' => gmdate('c'),
        ]);
    }
}
