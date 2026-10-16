<?php
declare(strict_types=1);
use Cms\Core\Migration\MigrationInterface;
return new class implements MigrationInterface {
 public function id():string{return '2026_10_13_000001_homepage_sources';}
 public function up(PDO $pdo):void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS cms_homepage_selection (id INTEGER PRIMARY KEY, revision INTEGER NOT NULL, state_json TEXT NOT NULL)");
  $pdo->exec("CREATE TABLE IF NOT EXISTS cms_homepage_leases (owner_id VARCHAR(96) PRIMARY KEY, lease_json TEXT NOT NULL)");
  if(!$pdo->query('SELECT id FROM cms_homepage_selection WHERE id=1')->fetchColumn())$pdo->exec("INSERT INTO cms_homepage_selection VALUES (1,0,'{\"provider\":\"core.theme\",\"owner\":\"core\",\"token\":\"\"}')");
 }
};
