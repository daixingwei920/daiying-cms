<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use PDO;

final class PluginDataStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $pluginId,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function put(string $type, string $key, array $payload): void
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_plugin_data (plugin_id, data_type, data_key, payload_json, created_at, updated_at)
             VALUES (:plugin_id, :data_type, :data_key, :payload_json, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':plugin_id' => $this->pluginId,
            ':data_type' => $type,
            ':data_key' => $key,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    /** Atomic, plugin-scoped documents. The callback must not perform network I/O. */
    public function document(string $type, string $key): ?array
    {
        $q=$this->pdo->prepare('SELECT payload_json FROM cms_plugin_documents WHERE plugin_id=? AND data_type=? AND data_key=?');
        $q->execute([$this->pluginId,$type,$key]); $json=$q->fetchColumn();
        return is_string($json) ? json_decode($json,true,64,JSON_THROW_ON_ERROR) : null;
    }

    public function mutate(string $type,string $key,callable $change): array
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D',$type) || $key==='' || strlen($key)>191) throw new PluginException('invalid_request');
        \Cms\Core\Payment\PaymentTransaction::beginImmediate($this->pdo);
        try {
            $this->pdo->exec('UPDATE cms_plugin_document_lock SET marker=marker WHERE lock_id=1');
            $old=$this->document($type,$key); $next=$change($old);
            if (!is_array($next)) throw new PluginException('invalid_request');
            $json=json_encode($next,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if(strlen($json)>2097152)throw new PluginException('document_limit');
            if($old===null)$this->pdo->prepare('INSERT INTO cms_plugin_documents(plugin_id,data_type,data_key,payload_json,revision,updated_at) VALUES(?,?,?,?,1,?)')->execute([$this->pluginId,$type,$key,$json,gmdate('c')]);
            else $this->pdo->prepare('UPDATE cms_plugin_documents SET payload_json=?,revision=revision+1,updated_at=? WHERE plugin_id=? AND data_type=? AND data_key=?')->execute([$json,gmdate('c'),$this->pluginId,$type,$key]);
            \Cms\Core\Payment\PaymentTransaction::commit($this->pdo); return $next;
        } catch(\Throwable $e){if(\Cms\Core\Payment\PaymentTransaction::isActive($this->pdo))\Cms\Core\Payment\PaymentTransaction::rollback($this->pdo);throw $e;}
    }

    public function documents(string $type, int $limit=100): array
    {
        $q=$this->pdo->prepare('SELECT data_key,payload_json FROM cms_plugin_documents WHERE plugin_id=? AND data_type=? ORDER BY updated_at DESC LIMIT '.max(1,min(100,$limit)));
        $q->execute([$this->pluginId,$type]); return $q->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function all(string $type): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT data_key, payload_json, created_at, updated_at FROM cms_plugin_data
             WHERE plugin_id = :plugin_id AND data_type = :data_type ORDER BY id DESC'
        );
        $stmt->execute([
            ':plugin_id' => $this->pluginId,
            ':data_type' => $type,
        ]);

        return $stmt->fetchAll();
    }
}
