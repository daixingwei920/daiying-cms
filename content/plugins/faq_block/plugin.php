<?php

declare(strict_types=1);

use Cms\Core\Plugin\PluginContext;

return static function (PluginContext $context): void {
    $context->registerBlock('faq', 'FAQ');
};
