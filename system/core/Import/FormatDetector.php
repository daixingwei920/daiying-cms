<?php

declare(strict_types=1);

namespace Cms\Core\Import;

final class FormatDetector
{
    public function detect(string $payload): string
    {
        $trimmed = ltrim($payload);
        if (str_starts_with($trimmed, '<?xml') || str_starts_with($trimmed, '<rss')) {
            return 'xml';
        }

        $json = json_decode($payload, true);
        if (is_array($json)) {
            return 'json';
        }

        if (str_contains($payload, "log_ID\t") || str_contains($payload, '"post_title"')) {
            return 'csv';
        }

        return 'text';
    }
}
