<?php
declare(strict_types=1);

require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Plugin\PackageScanner;

$tmp = sys_get_temp_dir() . '/plugin-manifest-scan-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
$checks = 0;
$manifest = json_encode(['plugin_id'=>'example.payment', 'capabilities'=>['network.external']], JSON_THROW_ON_ERROR);
$withoutNetwork = json_encode(['plugin_id'=>'example.payment', 'capabilities'=>[]], JSON_THROW_ON_ERROR);
$network = '<?php $gateway = "https://example.invalid/pay";';

$check = static function (array $entries, ?string $code, string $message) use ($tmp, &$checks): void {
    $path = $tmp . '/' . $checks . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE) !== true) { throw new RuntimeException('Cannot create fixture ZIP.'); }
    foreach ($entries as $name=>$content) { $zip->addFromString($name, $content); }
    $zip->close();
    $findings = (new PackageScanner())->scanZip($path);
    $matches = array_values(array_filter($findings, static fn (array $finding): bool => $finding['code'] === $code));
    if ($code === null ? $findings !== [] : $matches === []) {
        throw new RuntimeException($message . ': ' . json_encode($findings, JSON_THROW_ON_ERROR));
    }
    $checks++;
    echo 'PASS ' . $message . PHP_EOL;
};

try {
    $check(['plugin.json'=>$manifest,'plugin.php'=>$network], null, 'legacy flat manifest declarations remain recognized');
    $check(['example.payment/plugin.json'=>$manifest,'example.payment/plugin.php'=>$network], null, 'native plugin-ID-root manifest declarations are recognized');
    $check(['plugin.json'=>$withoutNetwork,'plugin.php'=>$network], 'capability_mismatch', 'flat undeclared network remains rejected');
    $check(['example.payment/plugin.json'=>$withoutNetwork,'example.payment/plugin.php'=>$network], 'capability_mismatch', 'nested undeclared network remains rejected');
    $check(['example.payment/plugin.php'=>$network], 'capability_mismatch', 'missing manifest does not grant network capability');
    $check(['example.payment/plugin.json'=>'{','example.payment/plugin.php'=>$network], 'invalid_manifest', 'malformed manifest fails closed');
    $check(['wrong.id/plugin.json'=>$manifest,'wrong.id/plugin.php'=>$network], 'manifest_identity', 'directory and manifest identity must match');
    $check(['plugin.json'=>$manifest,'example.payment/plugin.json'=>$withoutNetwork,'example.payment/plugin.php'=>$network], 'ambiguous_manifest', 'root manifest cannot spoof nested installed declarations');
    $check(['example.payment/plugin.json'=>$manifest,'other.payment/plugin.json'=>$manifest], 'ambiguous_manifest', 'multiple manifest candidates fail closed');
    $check(['example.payment/plugin.json'=>$manifest,'outside.php'=>$network], 'manifest_scope', 'wrapper declaration cannot authorize files outside its root');
    foreach (['dangerous_call'=>'<?php eval("1");','risky_io'=>'<?php exec("test");','obfuscation'=>'<?php base64_decode("eA==");'] as $expected=>$php) {
        $check(['example.payment/plugin.json'=>$manifest,'example.payment/plugin.php'=>$php], $expected, 'declared network does not hide ' . $expected);
    }
    $check(['example.payment/plugin.json'=>$manifest,'example.payment/evil.exe'=>'test'], 'binary', 'executable binary detection remains active');
    $check(['example.payment/plugin.json'=>$manifest,'example.payment/inner.zip'=>'test'], 'nested_zip', 'nested ZIP detection remains active');
    $check(['example.payment/plugin.json'=>$manifest,'example.payment/../escape.php'=>'<?php'], 'unsafe_path', 'path traversal detection remains active');
    $check(['plugin.json'=>$manifest,'system/evil.php'=>'<?php'], 'core_write', 'protected Core path detection remains active');
    echo 'PASS TOTAL ' . $checks . PHP_EOL;
} finally {
    foreach (glob($tmp . '/*.zip') ?: [] as $file) { unlink($file); }
    rmdir($tmp);
}
