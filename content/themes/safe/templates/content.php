<?php
declare(strict_types=1);
use Cms\Core\Theme\TemplateContext;
/** @var TemplateContext $context */
?><!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title><?= $context->e($context->get('title', 'Content')) ?></title><meta name="robots" content="noindex,nofollow"></head><body><main><h1><?= $context->e($context->get('title', 'Content')) ?></h1><?= $context->get('rendered_blocks', '') ?></main></body></html>
