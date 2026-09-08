<?php

declare(strict_types=1);

namespace Cms\Core\Mail;

use PDO;

final class MailTemplateRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param list<string> $variables */
    public function register(string $templateId, string $subject, string $html, string $text, array $variables = [], string $locale = 'default', bool $enabled = true): void
    {
        $this->assertTemplateId($templateId);
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_mail_templates (template_id, locale, subject, html_body, text_body, variables_json, enabled, owner, created_at, updated_at)
             VALUES (:template_id, :locale, :subject, :html_body, :text_body, :variables_json, :enabled, "core", :created_at, :updated_at)'
        );
        try {
            $stmt->execute([
                ':template_id' => $templateId,
                ':locale' => $this->locale($locale),
                ':subject' => $this->text($subject, 998),
                ':html_body' => $this->body($html),
                ':text_body' => $this->body($text),
                ':variables_json' => json_encode(array_values($variables), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':enabled' => $enabled ? 1 : 0,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (\Throwable) {
            $this->pdo->prepare(
                'UPDATE cms_mail_templates SET subject = :subject, html_body = :html_body, text_body = :text_body, variables_json = :variables_json, enabled = :enabled, updated_at = :updated_at WHERE template_id = :template_id AND locale = :locale'
            )->execute([
                ':template_id' => $templateId,
                ':locale' => $this->locale($locale),
                ':subject' => $this->text($subject, 998),
                ':html_body' => $this->body($html),
                ':text_body' => $this->body($text),
                ':variables_json' => json_encode(array_values($variables), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':enabled' => $enabled ? 1 : 0,
                ':updated_at' => $now,
            ]);
        }
    }

    /** @param array<string,string|int|float|bool|null> $variables */
    public function render(string $templateId, array $variables = [], string $locale = 'default'): ?MailMessage
    {
        $template = $this->find($templateId, $locale);
        if ($template === null || (int) ($template['enabled'] ?? 0) !== 1) {
            return null;
        }

        return new MailMessage(
            [new MailAddress('placeholder@example.invalid')],
            $this->replace((string) $template['subject'], $variables),
            $this->replace((string) $template['html_body'], $variables),
            $this->replace((string) $template['text_body'], $variables),
            [],
            [],
            null,
            [],
            $locale,
            $templateId,
            $variables,
        );
    }

    /** @return array<string,mixed>|null */
    public function find(string $templateId, string $locale = 'default'): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_mail_templates WHERE template_id = :template_id AND locale IN (:locale, "default") ORDER BY CASE WHEN locale = :locale THEN 0 ELSE 1 END LIMIT 1');
        $stmt->execute([':template_id' => $templateId, ':locale' => $this->locale($locale)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string,string|int|float|bool|null> $variables */
    public function replace(string $value, array $variables): string
    {
        return preg_replace_callback('/{{\s*([A-Za-z0-9_.-]+)\s*}}/', static function (array $match) use ($variables): string {
            $key = (string) $match[1];
            return htmlspecialchars((string) ($variables[$key] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $value) ?: $value;
    }

    private function assertTemplateId(string $templateId): void
    {
        if (preg_match('/^[a-z0-9._-]{2,120}$/', $templateId) !== 1) {
            throw new MailException('Mail template id is invalid.');
        }
    }

    private function locale(string $locale): string
    {
        $locale = trim($locale) ?: 'default';
        if (strlen($locale) > 32 || preg_match('/^[A-Za-z0-9_-]+$/', $locale) !== 1) {
            throw new MailException('Mail template locale is invalid.');
        }

        return $locale;
    }

    private function text(string $value, int $max): string
    {
        if ($value === '' || strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new MailException('Mail template text is invalid.');
        }

        return $value;
    }

    private function body(string $value): string
    {
        if (strlen($value) > 1048576 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new MailException('Mail template body is invalid.');
        }

        return $value;
    }
}
