<?php
declare(strict_types=1);

$up=static function(PDO $pdo):void{
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $id=$sqlite?'INTEGER PRIMARY KEY AUTOINCREMENT':'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $text=$sqlite?'TEXT':'LONGTEXT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurant_pos_connections (
        id $id, brand VARCHAR(20) NOT NULL, environment VARCHAR(20) NOT NULL,
        site_hash VARCHAR(64) NOT NULL, revision INT NOT NULL DEFAULT 1,
        status VARCHAR(32) NOT NULL DEFAULT 'disconnected', account_id VARCHAR(128) NOT NULL DEFAULT '',
        location_id VARCHAR(128) NOT NULL DEFAULT '', location_name VARCHAR(191) NOT NULL DEFAULT '',
        currency VARCHAR(3) NOT NULL DEFAULT '', locations_json $text NULL, catalog_json $text NULL,
        mapping_json $text NULL, settings_json $text NULL, auto_send INT NOT NULL DEFAULT 0,
        start_after_order_id BIGINT NOT NULL DEFAULT 0, scan_cursor BIGINT NOT NULL DEFAULT 0, worker_seen_at BIGINT NOT NULL DEFAULT 0,
        probe_job_id BIGINT NULL, probe_confirmed INT NOT NULL DEFAULT 0,
        lease_token VARCHAR(64) NOT NULL DEFAULT '', lease_until BIGINT NOT NULL DEFAULT 0,
        last_error VARCHAR(64) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        UNIQUE(brand, environment)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurant_pos_oauth_states (
        state_hash VARCHAR(64) PRIMARY KEY, brand VARCHAR(20) NOT NULL, environment VARCHAR(20) NOT NULL,
        actor_id BIGINT NOT NULL, session_hash VARCHAR(64) NOT NULL, app_hash VARCHAR(64) NOT NULL,
        expires_at BIGINT NOT NULL, used_at BIGINT NULL, created_at DATETIME NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurant_pos_jobs (
        id $id, connection_id BIGINT NOT NULL, revision INT NOT NULL, commerce_order_id BIGINT NULL,
        dedup_key VARCHAR(191) NOT NULL UNIQUE, reference VARCHAR(40) NOT NULL UNIQUE,
        status VARCHAR(32) NOT NULL DEFAULT 'queued', stage VARCHAR(32) NOT NULL DEFAULT 'new',
        snapshot_json $text NOT NULL, remote_order_id VARCHAR(128) NOT NULL DEFAULT '',
        remote_payment_id VARCHAR(128) NOT NULL DEFAULT '', attempts INT NOT NULL DEFAULT 0,
        claim_token VARCHAR(64) NOT NULL DEFAULT '', last_error VARCHAR(64) NOT NULL DEFAULT '',
        is_test INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurant_pos_audit (
        id $id, connection_id BIGINT NULL, job_id BIGINT NULL, actor_id BIGINT NULL,
        action VARCHAR(64) NOT NULL, detail VARCHAR(500) NOT NULL DEFAULT '', created_at DATETIME NOT NULL
    )");
};
return ['id'=>'official_restaurant_005_pos_connections','affected_objects'=>['table:restaurant_pos_connections','table:restaurant_pos_oauth_states','table:restaurant_pos_jobs','table:restaurant_pos_audit'],'up'=>$up,'down'=>static function(PDO $pdo):void{}];
