<?php

declare(strict_types=1);

namespace Cms\Core\Import;

use SimpleXMLElement;

final class WordPressImporter implements ImporterAdapterInterface
{
    public function platformId(): string
    {
        return 'wordpress';
    }

    public function supports(string $sourceType, string $sample): bool
    {
        return $sourceType === 'xml' && (str_contains($sample, '<wp:') || str_contains($sample, 'xmlns:wp='));
    }

    public function parse(string $payload): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($payload);
        if (!$xml instanceof SimpleXMLElement || !isset($xml->channel->item)) {
            throw new ImportException('Invalid WordPress XML payload.');
        }

        $items = [];
        foreach ($xml->channel->item as $item) {
            $title = trim((string) $item->title);
            if ($title === '') {
                continue;
            }
            $slug = basename(parse_url((string) $item->link, PHP_URL_PATH) ?: '');
            $items[] = new UnifiedContent(
                'article',
                $title,
                $slug,
                'draft',
                [['type' => 'paragraph', 'data' => ['text' => trim(strip_tags((string) $item->description))]]],
                ['source_platform' => $this->platformId()],
                (string) $item->link,
            );
        }

        return $items;
    }
}
