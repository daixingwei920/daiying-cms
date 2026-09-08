<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Support\PublicApiRegistry;

final class AiContextService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ?ContentRepository $content = null,
        private readonly ?MediaLibrary $media = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function site(): array
    {
        return [
            'name' => (string) $this->settings->get('site.name', 'Daiying CMS'),
            'url' => (string) $this->settings->get('site.url', ''),
            'timezone' => (string) $this->settings->get('site.timezone', 'UTC'),
        ];
    }

    /** @return array<string,mixed>|null */
    public function content(int $id): ?array
    {
        if ($this->content === null || !method_exists($this->content, 'find')) {
            return null;
        }
        $row = $this->content->find($id);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function media(int $id): ?array
    {
        if ($this->media === null || !method_exists($this->media, 'find')) {
            return null;
        }
        $row = $this->media->find($id);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    public function publicCapabilities(): array
    {
        return [
            'api_versions' => PublicApiRegistry::versions(),
            'contracts' => PublicApiRegistry::ids(),
            'ai_tools' => AiToolRegistry::all(),
            'ai_agents' => AiAgentRegistry::all(),
            'ai_prompts' => AiPromptRegistry::all(),
        ];
    }
}
