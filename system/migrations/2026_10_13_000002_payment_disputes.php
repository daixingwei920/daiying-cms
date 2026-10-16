<?php
declare(strict_types=1);
use Cms\Core\Migration\MigrationInterface;
return new class implements MigrationInterface {
 public function id():string{return '2026_10_13_000002_payment_disputes';}
 public function up(PDO $pdo):void {
  $tail=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
  $pdo->exec("CREATE TABLE IF NOT EXISTS cms_payment_disputes (provider_id VARCHAR(96) NOT NULL, dispute_id VARCHAR(191) NOT NULL, payment_id INTEGER NOT NULL, merchant_id VARCHAR(191) NOT NULL, state VARCHAR(32) NOT NULL, outcome VARCHAR(32) NOT NULL, provider_sequence BIGINT NULL, revision BIGINT NOT NULL, verified_at VARCHAR(64) NOT NULL, PRIMARY KEY(provider_id,dispute_id), FOREIGN KEY(payment_id) REFERENCES cms_payments(id))$tail");
  $pdo->exec("CREATE TABLE IF NOT EXISTS cms_payment_dispute_events (provider_id VARCHAR(96) NOT NULL, event_id VARCHAR(191) NOT NULL, payload_hash VARCHAR(64) NOT NULL, payment_id INTEGER NOT NULL, dispute_id VARCHAR(191) NOT NULL, status VARCHAR(32) NOT NULL, created_at VARCHAR(64) NOT NULL, PRIMARY KEY(provider_id,event_id), FOREIGN KEY(payment_id) REFERENCES cms_payments(id))$tail");
  if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->exec('CREATE INDEX IF NOT EXISTS idx_disputes_payment ON cms_payment_disputes(payment_id)');
  elseif(!$pdo->query("SHOW INDEX FROM cms_payment_disputes WHERE Key_name='idx_disputes_payment'")->fetch())$pdo->exec('CREATE INDEX idx_disputes_payment ON cms_payment_disputes(payment_id)');
 }
};
