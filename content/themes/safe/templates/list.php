<?php
declare(strict_types=1);
use Cms\Core\Theme\TemplateContext;
/** @var TemplateContext $context */
?><!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title><?= $context->e($context->get('title', 'List')) ?></title><meta name="robots" content="noindex,nofollow"></head><body><main><h1><?= $context->e($context->get('title', 'List')) ?></h1></main></body></html>
