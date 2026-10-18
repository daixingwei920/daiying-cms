<?php
declare(strict_types=1);
use Cms\Core\Migration\MigrationInterface;
return new class implements MigrationInterface {
 public function id():string{return '2026_10_18_000002_plugin_documents';}
 public function up(PDO $pdo):void{
  $long=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'TEXT':'LONGTEXT';
  $pdo->exec('CREATE TABLE IF NOT EXISTS cms_plugin_documents (plugin_id VARCHAR(96) NOT NULL,data_type VARCHAR(64) NOT NULL,data_key VARCHAR(191) NOT NULL,payload_json '.$long.' NOT NULL,revision INTEGER NOT NULL,updated_at VARCHAR(64) NOT NULL,PRIMARY KEY(plugin_id,data_type,data_key))');
  $pdo->exec('CREATE TABLE IF NOT EXISTS cms_plugin_document_lock (lock_id INTEGER PRIMARY KEY,marker INTEGER NOT NULL)');
  if(!(int)$pdo->query('SELECT COUNT(*) FROM cms_plugin_document_lock WHERE lock_id=1')->fetchColumn())$pdo->exec('INSERT INTO cms_plugin_document_lock VALUES(1,0)');
 }
};
