<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use Cms\Core\Advertising\AdRepository;
use Throwable;

final class PluginAdvertisingService
{
    public function __construct(private readonly PluginManifest $manifest, private readonly ?AdRepository $repository)
    {
    }

    /** @param array<mixed> $slotKeys @return array<string,array{label:string,enabled:bool,html:string}> */
    public function renderSlots(array $slotKeys): array
    {
        if (!in_array('ads.read', $this->manifest->capabilities, true)) {
            throw new PluginException('capability_denied');
        }
        $keys = [];
        foreach ($slotKeys as $key) {
            if (!is_string($key)) {
                throw new PluginException('invalid_request');
            }
            $key = strtolower(trim($key));
            if (preg_match('/^[a-z0-9_-]{1,48}$/', $key) !== 1) {
                throw new PluginException('invalid_request');
            }
            $keys[$key] = true;
        }
        if ($keys === []) {
            return [];
        }
        if ($this->repository === null) {
            throw new PluginException('service_unavailable');
        }
        try {
            return $this->repository->requestedActiveSlotsForRender(array_keys($keys));
        } catch (Throwable $exception) {
            throw new PluginException('service_unavailable', 0, $exception);
        }
    }
}
