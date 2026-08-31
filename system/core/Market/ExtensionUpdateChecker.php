<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class ExtensionUpdateChecker
{
    public function __construct(
        private readonly MarketInstallRepository $repository,
        private readonly MarketApiClientInterface $client,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function availableUpdates(string $type = ''): array
    {
        $updates = [];
        foreach ($this->repository->latestInstalledByExtension($type) as $installed) {
            $metadata = json_decode((string) ($installed['metadata_json'] ?? '{}'), true);
            if ((string) ($installed['source'] ?? '') === 'uninstalled' || (is_array($metadata) && (string) ($metadata['status'] ?? '') === 'Uninstalled')) {
                continue;
            }
            $items = $this->client->search((string) $installed['extension_type']);
            foreach ($items as $item) {
                if ($item->extensionId !== (string) $installed['extension_id']) {
                    continue;
                }
                if (!$item->compatible) {
                    $updates[] = [
                        'extension_id' => $item->extensionId,
                        'type' => $item->type,
                        'current_version' => (string) $installed['version'],
                        'available_version' => $item->version,
                        'market_id' => $item->marketId,
                        'compatible' => false,
                        'message' => '当前 CMS 版本不兼容',
                    ];
                    continue;
                }
                if (version_compare($item->version, (string) $installed['version'], '>')) {
                    $updates[] = [
                        'extension_id' => $item->extensionId,
                        'type' => $item->type,
                        'current_version' => (string) $installed['version'],
                        'available_version' => $item->version,
                        'market_id' => $item->marketId,
                        'compatible' => true,
                    ];
                }
            }
        }

        return $updates;
    }
}
