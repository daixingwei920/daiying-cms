<?php

declare(strict_types=1);

use Cms\Core\Plugin\PluginContext;
use Local\Storage\Baidu\BaiduStoragePlugin;

require_once __DIR__ . '/src/BaiduStoragePlugin.php';
require_once __DIR__ . '/src/BaiduTokenRepository.php';
require_once __DIR__ . '/src/BaiduTokenRefreshLock.php';
require_once __DIR__ . '/src/BaiduOAuthService.php';
require_once __DIR__ . '/src/BaiduHttpTransport.php';
require_once __DIR__ . '/src/BaiduApiClient.php';
require_once __DIR__ . '/src/BaiduFileBrowser.php';
require_once __DIR__ . '/src/BaiduStorageProvider.php';

return static function (PluginContext $context): void {
    (new BaiduStoragePlugin($context))->register();
};
