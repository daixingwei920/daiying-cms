<?php

declare(strict_types=1);

namespace Cms\Core\Taxonomy;

use PDO;

final class TaxonomyRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        if (!in_array($taxonomy, ['category', 'tag'], true)) {
            throw new TaxonomyException('Unsupported taxonomy.');
        }

        $slug = $slug !== '' ? $slug : strtolower(preg_replace('/[^a-z0-9\p{Han}]+/u', '-', $name) ?? '');
        $stmt = $this->pdo->prepare('SELECT id FROM cms_terms WHERE taxonomy = :taxonomy AND slug = :slug LIMIT 1');
        $stmt->execute([':taxonomy' => $taxonomy, ':slug' => $slug]);
        $existing = $stmt->fetch();
        if (is_array($existing)) {
            return (int) $existing['id'];
        }

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_terms (taxonomy, name, slug, created_at, updated_at)
             VALUES (:taxonomy, :name, :slug, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':taxonomy' => $taxonomy,
            ':name' => $name,
            ':slug' => $slug,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function attach(int $contentId, int $termId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_content_terms (content_id, term_id, created_at)
             VALUES (:content_id, :term_id, :created_at)'
        );
        $stmt->execute([
            ':content_id' => $contentId,
            ':term_id' => $termId,
            ':created_at' => gmdate('c'),
        ]);
    }
}
