<?php

declare(strict_types=1);

namespace Cms\Core\Market;

use Cms\Core\Config\Settings;

final class ObjectStorageAdapterFactory
{
    public function __construct(private readonly Settings $settings, private readonly string $rootPath, private readonly ?object $sdkClient = null)
    {
    }

    public function make(): ObjectStorageAdapterInterface
    {
        $driver = (string) $this->settings->get('market.object_storage_driver', '');
        $baseUri = (string) $this->settings->get('market.object_storage_url', '');
        $secret = (string) $this->settings->get('market.object_storage_secret', '');
        if ($driver === 'sdk') {
            $sdkClient = $this->sdkClient ?? $this->configuredSdkClient();
            if ($sdkClient === null) {
                throw new MarketException('SDK object storage driver requires an SDK client.');
            }

            return new SdkObjectStorageAdapter($sdkClient, (string) $this->settings->get('market.object_storage_bucket', ''));
        }
        if ($driver === 'remote' || $baseUri !== '') {
            return new RemoteObjectStorageAdapter($baseUri, $secret === '' ? [] : ['X-Signature-Secret' => $secret]);
        }

        return new LocalObjectStorageAdapter($this->rootPath . '/storage/market/remote-objects');
    }

    private function configuredSdkClient(): ?object
    {
        $className = trim((string) $this->settings->get('market.object_storage_sdk_client', ''));
        if ($className === '' || !class_exists($className)) {
            return null;
        }

        return new $className();
    }
}
