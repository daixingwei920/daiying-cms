<?php

declare(strict_types=1);
namespace Cms\Core\Ai;

/** Additive contract; does not change AiProviderInterface or text requests. */
interface AiImageProviderInterface
{
    /** @return list<AiModel> Documentation-backed protocol declarations, not account acceptance. */
    public function models(): array;
    public function assertSupported(array $config): void;
    /** @return array{png:string,usage:array} Binary PNG stays server-side. */
    public function execute(array $request, array $config, ?string $inputPng = null): array;
}
