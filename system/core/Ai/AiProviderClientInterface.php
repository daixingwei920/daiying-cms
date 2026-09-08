<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

interface AiProviderClientInterface
{
    /**
     * @param list<array{role:string,content:string}> $messages
     * @param array<string,mixed> $config
     * @return array{provider:string,model:string,content:string,raw?:array<string,mixed>}
     */
    public function chat(array $messages, array $config): array;
}
