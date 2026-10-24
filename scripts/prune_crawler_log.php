<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';
(new Cms\Core\Seo\CrawlerLog(CMS_ROOT, Cms\Core\Config\Settings::load(CMS_ROOT)))->prune();
echo "Crawler retention cleanup attempted; locked/unavailable storage is skipped.\n";
