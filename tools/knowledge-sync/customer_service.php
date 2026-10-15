<?php

declare(strict_types=1);

// Standalone isolated adapter. Reuse the installed plugin repository; no Core
// schema changes, provider calls, migration rewrites or production bootstrap.
try {
    $verify = ($argv[1] ?? '') === '--verify';
    $parameters = array_slice($argv, $verify ? 2 : 1);
    if (count($parameters) !== 3) {
        throw new RuntimeException('Expected isolated database, plugin root and site id.');
    }
    [$database, $pluginRoot, $siteId] = $parameters;
    $marker = json_decode((string) file_get_contents($database . '.isolated.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_file($database) || $marker !== ['environment' => 'isolated', 'site_id' => $siteId]) {
        throw new RuntimeException('Isolated database marker mismatch.');
    }
    $lock = json_decode((string) file_get_contents(__DIR__ . '/reuse-lock.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($lock['sha256'] as $relative => $hash) {
        if (!hash_equals($hash, (string) hash_file('sha256', $pluginRoot . '/' . $relative))) {
            throw new RuntimeException('Plugin baseline mismatch.');
        }
    }
    require_once $pluginRoot . '/src/Repository/AiCustomerServiceRepository.php';
    $raw = stream_get_contents(STDIN);
    $snapshot = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (($snapshot['environment'] ?? '') !== 'isolated' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $snapshot['source'] ?? '')) {
        throw new RuntimeException('Invalid isolated snapshot.');
    }
    $pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA busy_timeout = 3000');
    $repo = new Official\AiCustomerService\Repository\AiCustomerServiceRepository($pdo);
    if ($repo->siteId() !== $siteId) {
        throw new RuntimeException('Site scope mismatch.');
    }
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM ai_customer_service_knowledge_items WHERE site_id=:site AND source_type='knowledge_sync'");
    $stmt->execute([':site' => $siteId]);
    $existing = [];
    foreach ($stmt->fetchAll() as $row) {
        $meta = json_decode((string) ($row['metadata_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        if (($meta['managed_source'] ?? '') === $snapshot['source']) {
            if (isset($existing[$row['source_id']])) {
                throw new RuntimeException('Existing managed logical ids are not unique.');
            }
            $existing[$row['source_id']] = $row;
        }
    }
    $seen = [];
    $counts = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'withdrawn' => 0];
    foreach ($snapshot['records'] as $record) {
        if (($record['visibility'] ?? '') !== 'USER-FACING' || !in_array($record['priority'] ?? '', ['P0', 'P1', 'P2'], true)) {
            throw new RuntimeException('Non-public record rejected.');
        }
        if (trim($record['title']) === '' || trim($record['content']) === '' || mb_strlen($record['title']) > 255 || mb_strlen($record['topic']) > 191) {
            throw new RuntimeException('Invalid knowledge text.');
        }
        $sourceId = hash('sha256', $snapshot['source'] . ':' . $record['id']);
        if (isset($seen[$sourceId])) {
            throw new RuntimeException('Duplicate logical id.');
        }
        $seen[$sourceId] = true;
        $old = $existing[$sourceId] ?? null;
        $metadata = [
            'managed_source' => $snapshot['source'],
            'knowledge_id' => $record['id'],
            'content_hash' => $record['content_hash'],
            'version' => $snapshot['version'],
            'source_commit' => $snapshot['commit'],
            'knowledge_commit' => $snapshot['knowledge_commit'],
            'visibility' => 'USER-FACING',
            'priority' => $record['priority'],
            'source_path' => $record['source_path'],
            'url' => $record['url'] ?? '',
            'evidence' => $record['evidence'] ?? [],
        ];
        $trust = ['P0' => 'official', 'P1' => 'trusted', 'P2' => 'normal'][$record['priority']];
        $oldMetadata = $old ? json_decode((string) $old['metadata_json'], true, 512, JSON_THROW_ON_ERROR) : [];
        if ($old && (int) $old['enabled'] === 1 && $oldMetadata === $metadata
            && $old['title'] === $record['title'] && $old['content'] === $record['content']
            && $old['topic'] === $record['topic'] && $old['trust_level'] === $trust) {
            $counts['unchanged']++;
            continue;
        }
        if ($verify) {
            throw new RuntimeException('Consumer knowledge drift.');
        }
        $repo->saveKnowledgeItem('knowledge_sync', $sourceId, $record['title'], $record['content'],
            $record['priority'], $record['topic'], $trust, true, $metadata, $old ? (int) $old['id'] : null);
        $counts[$old ? 'updated' : 'added']++;
    }
    foreach ($existing as $sourceId => $old) {
        if (!isset($seen[$sourceId]) && (int) $old['enabled'] === 1) {
            if ($verify) {
                throw new RuntimeException('Unwithdrawn consumer knowledge.');
            }
            $repo->setKnowledgeEnabled((int) $old['id'], false);
            $counts['withdrawn']++;
        }
    }
    $pdo->commit();
    echo json_encode(['site_id' => $siteId, 'sha256' => hash('sha256', $raw), 'records' => count($seen), 'counts' => $counts], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Never relay database details, input text or provider secrets to the runner.
    fwrite(STDERR, "Isolated customer-service synchronization failed.\n");
    exit(1);
}
