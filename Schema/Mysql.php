<?php
namespace Kanboard\Plugin\Matinee\Schema;

use PDO;

const VERSION = 3;

/**
 * Spec §1 P1 tables, light scope. Deleting a user takes their servers with them.
 * is_shared: 0 private (the owner only), 1 shared whole with every active user.
 */
function version_1(PDO $pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS matinee_servers (
        id INT NOT NULL AUTO_INCREMENT,
        owner_user_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        base_url VARCHAR(255) NOT NULL,
        machine_id VARCHAR(64) NOT NULL,
        token_nonce TEXT NOT NULL,
        token_ct TEXT NOT NULL,
        key_id VARCHAR(16) NOT NULL,
        is_enabled TINYINT NOT NULL DEFAULT 1,
        is_shared TINYINT NOT NULL DEFAULT 0,
        status VARCHAR(16) NOT NULL DEFAULT \'ok\',
        last_checked_at BIGINT NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL DEFAULT 0,
        updated_at BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY(id),
        INDEX matinee_servers_owner_idx (owner_user_id),
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

/** Spec §11 S1: one row per connect or verify attempt, for the per-user rate limit. ServerModel prunes rows over 1 h old. */
function version_2(PDO $pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS matinee_attempts (
        user_id INT NOT NULL,
        created_at BIGINT NOT NULL,
        INDEX matinee_attempts_user_time_idx (user_id, created_at),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

/**
 * P3 (C4): one matinee_transcodes row per long stream (an HLS session, or an mp3 transcode while it runs), and the
 * one-row lock that serializes every cap claim. Deleting a server or a user takes their rows with them.
 */
function version_3(PDO $pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS matinee_transcodes (
        id INT NOT NULL AUTO_INCREMENT,
        server_id INT NOT NULL,
        user_id INT NOT NULL,
        session VARCHAR(36) NOT NULL,
        kind VARCHAR(8) NOT NULL,
        created_at BIGINT NOT NULL,
        last_seen_at BIGINT NOT NULL,
        stopped TINYINT NOT NULL DEFAULT 0,
        PRIMARY KEY(id),
        UNIQUE KEY matinee_transcodes_session_uq (session),
        INDEX matinee_transcodes_stale_idx (stopped, last_seen_at),
        INDEX matinee_transcodes_server_idx (server_id),
        INDEX matinee_transcodes_user_idx (user_id),
        FOREIGN KEY(server_id) REFERENCES matinee_servers(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS matinee_locks (
        name VARCHAR(32) NOT NULL,
        n INT NOT NULL DEFAULT 0,
        PRIMARY KEY(name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec("INSERT INTO matinee_locks (name, n) VALUES ('streams', 0)");
}
