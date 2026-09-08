<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

use PDO;

final class AiPromptRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function save(AiPromptTemplate $prompt, bool $enabled = true): void
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare('SELECT id FROM cms_ai_prompts WHERE prompt_id = :prompt_id AND version = :version AND language = :language LIMIT 1');
        $stmt->execute([':prompt_id' => $prompt->id, ':version' => $prompt->version, ':language' => $prompt->language]);
        $existing = $stmt->fetch();
        $payload = [
            ':prompt_id' => $prompt->id,
            ':version' => $prompt->version,
            ':owner_plugin' => $prompt->ownerPlugin,
            ':language' => $prompt->language,
            ':variables_json' => json_encode($prompt->variables, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':template' => $prompt->template,
            ':status' => $enabled ? 'enabled' : 'disabled',
            ':updated_at' => $now,
        ];
        if (is_array($existing)) {
            $this->pdo->prepare(
                'UPDATE cms_ai_prompts SET owner_plugin = :owner_plugin, variables_json = :variables_json, template = :template, status = :status, updated_at = :updated_at
                 WHERE prompt_id = :prompt_id AND version = :version AND language = :language'
            )->execute($payload);
            return;
        }
        $payload[':created_at'] = $now;
        $this->pdo->prepare(
            'INSERT INTO cms_ai_prompts (prompt_id, version, owner_plugin, language, variables_json, template, status, created_at, updated_at)
             VALUES (:prompt_id, :version, :owner_plugin, :language, :variables_json, :template, :status, :created_at, :updated_at)'
        )->execute($payload);
    }

    public function get(string $id, string $version = '1.0', string $language = 'default'): ?AiPromptTemplate
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM cms_ai_prompts
             WHERE prompt_id = :prompt_id AND version = :version AND language IN (:language, 'default') AND status = 'enabled'
             ORDER BY CASE WHEN language = :order_language THEN 0 ELSE 1 END LIMIT 1"
        );
        $stmt->execute([':prompt_id' => $id, ':version' => $version, ':language' => $language, ':order_language' => $language]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return AiPromptRegistry::get($id, $version, $language);
        }
        $variables = json_decode((string) ($row['variables_json'] ?? '[]'), true);

        return new AiPromptTemplate(
            (string) $row['prompt_id'],
            (string) $row['version'],
            (string) $row['template'],
            is_array($variables) ? array_values(array_map('strval', $variables)) : [],
            (string) $row['owner_plugin'],
            (string) $row['language'],
        );
    }
}
