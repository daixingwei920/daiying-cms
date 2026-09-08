<?php

declare(strict_types=1);

namespace Cms\Core\Media;

final class RemoteMediaProviderRegistry
{
    /** @var array<string,RemoteMediaProviderInterface> */
    private static array $providers = [];

    public static function register(RemoteMediaProviderInterface $provider): void
    {
        self::$providers[$provider->id()] = $provider;
    }

    public static function get(string $id): ?RemoteMediaProviderInterface
    {
        return self::$providers[$id] ?? null;
    }

    /** @return array<string,RemoteMediaProviderInterface> */
    public static function all(): array
    {
        return self::$providers;
    }

    /** @return array{id:string,label:string,api_version:string,capabilities:list<string>,available:bool} */
    public static function describe(RemoteMediaProviderInterface $provider): array
    {
        $capabilities = [
            StorageProviderCapabilities::READ,
            StorageProviderCapabilities::METADATA,
            StorageProviderCapabilities::DOWNLOAD,
        ];
        if (method_exists($provider, 'upload')) {
            $capabilities[] = StorageProviderCapabilities::UPLOAD;
        }
        if (method_exists($provider, 'delete')) {
            $capabilities[] = StorageProviderCapabilities::DELETE;
        }
        if (method_exists($provider, 'move')) {
            $capabilities[] = StorageProviderCapabilities::MOVE;
        }
        if (method_exists($provider, 'resolveUrl')) {
            $capabilities[] = StorageProviderCapabilities::PROXY_URL;
        }
        if ($provider instanceof RemoteMediaProviderV1Interface) {
            $capabilities = $provider->capabilities();
        }

        return [
            'id' => $provider->id(),
            'label' => $provider->label(),
            'api_version' => $provider instanceof RemoteMediaProviderV1Interface ? $provider->apiVersion() : 'legacy',
            'capabilities' => StorageProviderCapabilities::normalize($capabilities),
            'available' => self::available($provider),
        ];
    }

    /** @return array<string,array{id:string,label:string,api_version:string,capabilities:list<string>,available:bool}> */
    public static function descriptions(): array
    {
        $descriptions = [];
        foreach (self::$providers as $id => $provider) {
            $descriptions[$id] = self::describe($provider);
        }

        return $descriptions;
    }

    public static function available(RemoteMediaProviderInterface $provider): bool
    {
        if (!method_exists($provider, 'available')) {
            return true;
        }

        try {
            return (bool) $provider->available();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function clear(): void
    {
        self::$providers = [];
    }
}
