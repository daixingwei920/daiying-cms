<?php

declare(strict_types=1);
namespace Cms\Core\Plugin;

use Cms\Core\Ai\AiImageService;
use PDO;

/** Identity comes from Core's verified route scope, never browser payload. */
final class PluginAiImageService
{
    public function __construct(private readonly PluginManifest $manifest,private readonly PDO $pdo,private readonly AiImageService $images) {}
    public function models(): array { $this->actor(false); return $this->images->models(); }
    public function submit(string $idempotencyKey,array $request): array { return $this->images->submit($this->manifest->id,$this->actor(true),$idempotencyKey,$request,$this->manifest->version); }
    public function importReference(string $bytes): array { return $this->images->importReference($this->manifest->id,$this->actor(true),$bytes,$this->manifest->version); }
    public function find(string $id): array { return $this->images->find($id,$this->manifest->id,$this->actor(false)); }
    /** Opt-in request-driven execution, after Core has verified the POST member scope. */
    public function execute(string $id): array {
        $user = $this->actor(true);
        $job = $this->images->recoverOne($id,$this->manifest->id,$user);
        if (!in_array($job['status'], ['queued','response_ready'], true)) return $job;
        $this->images->work($id);
        return $this->images->find($id,$this->manifest->id,$user);
    }
    public function history(): array { return $this->images->history($this->manifest->id,$this->actor(false)); }
    public function imageBytes(string $id): string { return $this->images->imageBytes($id,$this->manifest->id,$this->actor(false)); }
    private function actor(bool $write): int {
        $s=PluginPaymentRequestScope::current();
        if(!in_array('ai.image',$this->manifest->capabilities,true) || !in_array('ai.use',$this->manifest->capabilities,true)
            || $s===null || $s['plugin_id']!==$this->manifest->id || !$s['actor_id'] || ($write && ($s['method']!=='POST'||!$s['csrf']))) throw new PluginException('capability_denied');
        $q=$this->pdo->prepare("SELECT version FROM cms_plugins WHERE plugin_id=? AND status='Enabled'"); $q->execute([$this->manifest->id]);
        if($q->fetchColumn()!==$this->manifest->version) throw new PluginException('capability_denied');
        $q=$this->pdo->prepare("SELECT id FROM cms_front_users WHERE id=? AND status='active'"); $q->execute([$s['actor_id']]);
        if(!$q->fetchColumn()) throw new PluginException('capability_denied');
        return (int)$s['actor_id'];
    }
}
