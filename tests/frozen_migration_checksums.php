<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$expected = [
    '2026_09_07_000001_official_plugins_registry' => '5828ba8c969916ce062fc06a9adfbfe6651033d6cecc5dede36edfb50b1732ed',
    '2026_09_07_000002_core_ai_settings' => '8f0f3299fb91f915c13c9b041687dba93b11ab3b850319a4a56c16342a32b71d',
];

foreach ($expected as $migration => $checksum) {
    $path = $root . '/system/migrations/' . $migration . '.php';
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] frozen migration missing: {$migration}\n");
        exit(1);
    }
    $actual = hash_file('sha256', $path);
    if ($actual !== $checksum) {
        fwrite(STDERR, "[FAIL] frozen migration checksum changed: {$migration}\nexpected={$checksum}\nactual={$actual}\n");
        exit(1);
    }
    echo "[PASS] frozen migration checksum preserved: {$migration}\n";
}

echo "Frozen migration checksum tests passed.\n";
