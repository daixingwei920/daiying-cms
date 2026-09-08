<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use PDO;

final class AI
{
    public static function forSite(string $rootPath, ?PDO $pdo = null, ?AiProviderClientInterface $client = null): AiService
    {
        $settings = Settings::load($rootPath);
        $pdo ??= ConnectionFactory::make($settings);

        return new AiService($pdo, $settings, $client);
    }
}
