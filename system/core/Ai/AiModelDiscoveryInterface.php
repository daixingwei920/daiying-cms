<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

interface AiModelDiscoveryInterface
{
    /**
     * @param array<string,mixed> $config
     * @return list<array{id:string,label:string,capabilities:list<string>}>
     */
    public function detectModels(array $config): array;
}
