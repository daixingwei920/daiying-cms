<?php
declare(strict_types=1);

[$script, $action, $database, $plugin, $site] = $argv;
$marker = json_decode((string) file_get_contents($database . '.isolated.json'), true, 512, JSON_THROW_ON_ERROR);
if ($marker !== ['environment' => 'isolated', 'site_id' => $site]) {
    throw new RuntimeException('Fixture marker required.');
}
$pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if ($action === 'init') {
    // Minimal isolated site identity; production Core tables are never accessed.
    $pdo->exec('CREATE TABLE cms_core_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    $pdo->prepare('INSERT INTO cms_core_settings VALUES (?,?)')->execute(['site.id', $site]);
    foreach (['001_foundation.php', '002_site_knowledge.php'] as $file) {
        $migration = require $plugin . '/migrations/' . $file;
        $migration['up']($pdo);
    }
    echo "{\"ok\":true}";
    exit;
}
require_once $plugin . '/src/Repository/AiCustomerServiceRepository.php';
require_once $plugin . '/src/Support/SafeText.php';
require_once $plugin . '/src/Service/KnowledgeRetriever.php';
$repo = new Official\AiCustomerService\Repository\AiCustomerServiceRepository($pdo);
if ($action === 'foreign') {
    $pdo->prepare('UPDATE cms_core_settings SET setting_value=? WHERE setting_key=?')->execute(['other-site', 'site.id']);
    $repo->saveKnowledgeItem('manual', 'foreign', 'Oracle', 'Oracle SECRET FOREIGN', '', '', 'official', true);
    $pdo->prepare('UPDATE cms_core_settings SET setting_value=? WHERE setting_key=?')->execute([$site, 'site.id']);
    echo "{\"ok\":true}";
} elseif ($action === 'noise') {
    $repo->saveFaq('SQLite 安装', 'OLD FAQ MUST NOT OVERRIDE OFFICIAL KNOWLEDGE');
    for ($i=0;$i<510;$i++) { $repo->saveKnowledgeItem('manual','noise-'.$i,'SQLite 安装','OLD UNREVIEWED '.str_repeat('SQLite ',10),'','','official',true); }
    echo '{"ok":true}';
} elseif ($action === 'receipt') {
    echo json_encode(['mode'=>$repo->managedKnowledgeMode(),'receipt'=>$repo->knowledgeSyncReceipt('cms')],JSON_THROW_ON_ERROR);
} elseif ($action === 'query-version') {
    echo json_encode((new Official\AiCustomerService\Service\KnowledgeRetriever($repo,$argv[6]))->retrieve($argv[5]),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} elseif ($action === 'query') {
    echo json_encode((new Official\AiCustomerService\Service\KnowledgeRetriever($repo))->retrieve($argv[5]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} else {
    throw new RuntimeException('Unknown fixture action.');
}
