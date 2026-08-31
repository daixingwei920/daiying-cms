<?php

declare(strict_types=1);

namespace Cms\Core\ExternalMigration;

use SimpleXMLElement;

final class WordPressMigrationAdapter implements MigrationAdapterInterface
{
    use JsonMigrationAdapterTrait;

    public function id(): string
    {
        return 'wordpress';
    }

    public function label(): string
    {
        return 'WordPress';
    }

    public function supports(string $filename, string $payload): bool
    {
        $lower = strtolower($filename);
        return str_ends_with($lower, '.xml') && (str_contains($payload, '<rss') || str_contains($payload, '<wp:wxr_version'))
            || str_ends_with($lower, '.sql') && preg_match('/\bINSERT\s+INTO\s+`?[^`\s]*wp_posts`?/i', $payload) === 1
            || str_ends_with($lower, '.json') && str_contains(strtolower($payload), 'wordpress');
    }

    public function scan(string $filename, string $payload): array
    {
        $package = $this->toPackage($filename, $payload);

        return $this->scanFromPackage($package, $payload);
    }

    public function toPackage(string $filename, string $payload): array
    {
        $lower = strtolower($filename);
        if (str_ends_with($lower, '.xml')) {
            return $this->fromWxr($payload);
        }
        if (str_ends_with($lower, '.sql')) {
            return $this->fromSql($payload);
        }
        if (str_ends_with($lower, '.json')) {
            return $this->packageFromGenericJson($payload, 'wordpress', 'WordPress 站点');
        }

        throw new MigrationException('暂不支持这个 WordPress 迁移文件。');
    }

    /** @return array<string,mixed> */
    private function fromWxr(string $payload): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($payload, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if (!$xml instanceof SimpleXMLElement || !isset($xml->channel)) {
            throw new MigrationException('WordPress WXR/XML 解析失败。');
        }
        $xml->registerXPathNamespace('wp', 'http://wordpress.org/export/1.2/');
        $xml->registerXPathNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
        $xml->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $contents = [];
        $media = [];
        $comments = [];
        foreach ($xml->channel->item as $item) {
            $wp = $item->children('http://wordpress.org/export/1.2/');
            $type = (string) ($wp->post_type ?? '');
            if (!in_array($type, ['post', 'page', 'attachment'], true)) {
                continue;
            }
            $id = (string) ($wp->post_id ?? '');
            if ($type === 'attachment') {
                $media[] = [
                    'source_id' => $id,
                    'source_url' => (string) ($wp->attachment_url ?? $item->link ?? ''),
                    'title' => (string) $item->title,
                ];
                continue;
            }
            $categories = [];
            $tags = [];
            foreach ($item->category as $category) {
                $domain = (string) ($category['domain'] ?? '');
                if ($domain === 'category') {
                    $categories[] = (string) $category;
                } elseif ($domain === 'post_tag') {
                    $tags[] = (string) $category;
                }
            }
            foreach ($wp->comment as $comment) {
                $comments[] = [
                    'source_id' => (string) ($comment->comment_id ?? ''),
                    'content_source_id' => $id,
                    'author' => (string) ($comment->comment_author ?? ''),
                    'email' => (string) ($comment->comment_author_email ?? ''),
                    'website' => (string) ($comment->comment_author_url ?? ''),
                    'content' => (string) ($comment->comment_content ?? ''),
                    'created_at' => $this->timeValue((string) ($comment->comment_date_gmt ?? $comment->comment_date ?? '')),
                    'status' => ((string) ($comment->comment_approved ?? '0')) === '1' ? 'approved' : 'pending',
                    'parent_source_id' => (string) ($comment->comment_parent ?? ''),
                ];
            }
            $meta = ['source_platform' => 'wordpress', 'source_id' => $id, 'custom_fields' => []];
            foreach ($wp->postmeta as $postmeta) {
                $key = (string) ($postmeta->meta_key ?? '');
                $value = (string) ($postmeta->meta_value ?? '');
                if ($key !== '') {
                    $meta['custom_fields'][$key] = $value;
                }
            }
            $contents[] = [
                'source_id' => $id,
                'type' => $type === 'page' ? 'page' : 'article',
                'title' => (string) $item->title,
                'slug' => (string) ($wp->post_name ?? ''),
                'excerpt' => (string) $item->description,
                'content_html' => (string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded,
                'status' => ((string) ($wp->status ?? 'draft')) === 'publish' ? 'published' : 'draft',
                'published_at' => $this->timeValue((string) ($wp->post_date_gmt ?? $item->pubDate ?? '')),
                'updated_at' => $this->timeValue((string) ($wp->post_modified_gmt ?? '')),
                'author_ref' => (string) $item->children('http://purl.org/dc/elements/1.1/')->creator,
                'categories' => $categories,
                'tags' => $tags,
                'featured_image_ref' => (string) ($meta['custom_fields']['_thumbnail_id'] ?? ''),
                'source_url' => (string) $item->link,
                'metadata' => $meta,
            ];
        }

        return [
            'migration_package_version' => '1',
            'source_system' => 'wordpress',
            'source_version' => (string) ($xml->channel->children('http://wordpress.org/export/1.2/')->wxr_version ?? ''),
            'site' => ['source_site_id' => 'wordpress:' . substr(hash('sha256', $payload), 0, 16), 'title' => (string) ($xml->channel->title ?? 'WordPress 站点')],
            'users' => [],
            'categories' => [],
            'tags' => [],
            'contents' => $contents,
            'media' => $media,
            'comments' => $comments,
            'redirects' => [],
            'metadata' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function fromSql(string $payload): array
    {
        $tables = (new SqlDumpInsertParser(['posts', 'terms', 'term_taxonomy', 'term_relationships', 'users', 'postmeta', 'comments']))->parse($payload);
        $terms = [];
        foreach ($tables['terms'] ?? [] as $term) {
            $terms[(string) ($term['term_id'] ?? '')] = $term;
        }
        $taxonomies = [];
        foreach ($tables['term_taxonomy'] ?? [] as $tax) {
            $taxonomies[(string) ($tax['term_taxonomy_id'] ?? '')] = $tax;
        }
        $relationships = [];
        foreach ($tables['term_relationships'] ?? [] as $rel) {
            $relationships[(string) ($rel['object_id'] ?? '')][] = (string) ($rel['term_taxonomy_id'] ?? '');
        }
        $meta = [];
        foreach ($tables['postmeta'] ?? [] as $row) {
            $meta[(string) ($row['post_id'] ?? '')][(string) ($row['meta_key'] ?? '')] = (string) ($row['meta_value'] ?? '');
        }
        $comments = [];
        foreach ($tables['comments'] ?? [] as $comment) {
            $comments[] = [
                'source_id' => (string) ($comment['comment_ID'] ?? ''),
                'content_source_id' => (string) ($comment['comment_post_ID'] ?? ''),
                'author' => (string) ($comment['comment_author'] ?? ''),
                'email' => (string) ($comment['comment_author_email'] ?? ''),
                'website' => (string) ($comment['comment_author_url'] ?? ''),
                'content' => (string) ($comment['comment_content'] ?? ''),
                'created_at' => $this->timeValue($comment['comment_date_gmt'] ?? $comment['comment_date'] ?? null),
                'status' => ((string) ($comment['comment_approved'] ?? '0')) === '1' ? 'approved' : 'pending',
                'parent_source_id' => (string) ($comment['comment_parent'] ?? ''),
            ];
        }
        $contents = [];
        $media = [];
        foreach ($tables['posts'] ?? [] as $post) {
            $type = (string) ($post['post_type'] ?? '');
            if ($type === 'attachment') {
                $media[] = ['source_id' => (string) ($post['ID'] ?? ''), 'source_url' => (string) ($post['guid'] ?? ''), 'title' => (string) ($post['post_title'] ?? '')];
                continue;
            }
            if (!in_array($type, ['post', 'page'], true)) {
                continue;
            }
            $id = (string) ($post['ID'] ?? '');
            $cats = [];
            $tags = [];
            foreach ($relationships[$id] ?? [] as $ttid) {
                $tax = $taxonomies[$ttid] ?? [];
                $term = $terms[(string) ($tax['term_id'] ?? '')] ?? [];
                if (($tax['taxonomy'] ?? '') === 'category') {
                    $cats[] = (string) ($term['name'] ?? '');
                } elseif (($tax['taxonomy'] ?? '') === 'post_tag') {
                    $tags[] = (string) ($term['name'] ?? '');
                }
            }
            $contents[] = [
                'source_id' => $id,
                'type' => $type === 'page' ? 'page' : 'article',
                'title' => (string) ($post['post_title'] ?? ''),
                'slug' => (string) ($post['post_name'] ?? ''),
                'excerpt' => (string) ($post['post_excerpt'] ?? ''),
                'content_html' => (string) ($post['post_content'] ?? ''),
                'status' => ((string) ($post['post_status'] ?? 'draft')) === 'publish' ? 'published' : 'draft',
                'published_at' => $this->timeValue($post['post_date_gmt'] ?? $post['post_date'] ?? null),
                'updated_at' => $this->timeValue($post['post_modified_gmt'] ?? null),
                'author_ref' => (string) ($post['post_author'] ?? ''),
                'categories' => array_values(array_filter($cats)),
                'tags' => array_values(array_filter($tags)),
                'featured_image_ref' => (string) ($meta[$id]['_thumbnail_id'] ?? ''),
                'source_url' => (string) ($post['guid'] ?? ''),
                'metadata' => ['source_platform' => 'wordpress', 'source_id' => $id, 'custom_fields' => $meta[$id] ?? []],
            ];
        }

        return ['migration_package_version' => '1', 'source_system' => 'wordpress', 'source_version' => '', 'site' => ['source_site_id' => 'wordpress:' . substr(hash('sha256', $payload), 0, 16), 'title' => 'WordPress 站点'], 'users' => array_values($tables['users'] ?? []), 'categories' => [], 'tags' => [], 'contents' => $contents, 'media' => $media, 'comments' => $comments, 'redirects' => [], 'metadata' => []];
    }

    /** @return array<string,mixed> */
    private function scanFromPackage(array $package, string $payload): array
    {
        return ['source_system' => 'wordpress', 'source_version' => (string) ($package['source_version'] ?? ''), 'source_site_id' => (string) ($package['site']['source_site_id'] ?? 'wordpress:' . substr(hash('sha256', $payload), 0, 16)), 'counts' => ['users' => count($package['users'] ?? []), 'categories' => count($package['categories'] ?? []), 'tags' => count($package['tags'] ?? []), 'contents' => count($package['contents'] ?? []), 'media' => count($package['media'] ?? []), 'comments' => count($package['comments'] ?? []), 'redirects' => count($package['redirects'] ?? [])]];
    }
}
