<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

interface AiProviderInterface
{
    public function getId(): string;

    public function getLabel(): string;

    /** @return list<AiModel> */
    public function getModels(): array;

    /** @return list<string> */
    public function getCapabilities(): array;

    /** @param array<string,mixed> $config */
    public function execute(AiRequest $request, array $config): AiResponse;

    /** @param array<string,mixed> $config @return array{provider:string,model:string,status:string,message:string} */
    public function testConnection(array $config): array;
}
