<?php
declare(strict_types=1);
require dirname(__DIR__).'/system/core/Bootstrap/autoload.php';
use Cms\Core\Update\UpdatePackageManifest;
$m=UpdatePackageManifest::fromArray(['release_id'=>'isolated-support-contract','version'=>'1.2.82-rc1','created_at'=>'2026-10-10','files'=>[],'source_versions'=>['min'=>'1.2.81','max'=>'1.2.81'],'min_upgrade_from'=>'1.2.81','hard_min_version'=>'1.2.81','migration_floor'=>'1.2.81']);
foreach(['0.0.0'=>false,'1.2.52'=>false,'1.2.80'=>false,'1.2.81-rc1'=>false,'1.2.81'=>true,'1.2.82-rc1'=>false,'1.2.82'=>false] as $version=>$expected){if($m->supportsSourceVersion($version)!==$expected)throw new RuntimeException('Source range mismatch: '.$version);echo '[PASS] source '.$version."\n";}
// Reject policy override before signing material is requested; no signing secret is needed.
exec('cd '.escapeshellarg(dirname(__DIR__)).' && php scripts/build_exact_commit_update_package.php --commit=HEAD --version=1.2.82-rc1 --channel=testing --min-upgrade-from=1.2.52 2>&1',$output,$code);
if($code===0||!str_contains(implode("\n",$output),'require source version 1.2.81'))throw new RuntimeException('Builder accepted an unauthorized lower floor.');
echo "[PASS] builder cannot lower approved 1.2.82 floor\n";
