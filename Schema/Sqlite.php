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
        id INTEGER PRIMARY KEY,
        owner_user_id INTEGER NOT NULL,
        name VARCHAR(100) NOT NULL,
        base_url VARCHAR(255) NOT NULL,
        machine_id VARCHAR(64) NOT NULL,
        token_nonce TEXT NOT NULL,
        token_ct TEXT NOT NULL,
        key_id VARCHAR(16) NOT NULL,
        is_enabled INTEGER NOT NULL DEFAULT 1,
        is_shared INTEGER NOT NULL DEFAULT 0,
        status VARCHAR(16) NOT NULL DEFAULT \'ok\',
        last_checked_at INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )');
    $pdo->exec('CREATE INDEX matinee_servers_owner_idx ON matinee_servers(owner_user_id)');
}

/** Spec §11 S1: one row per connect or verify attempt, for the per-user rate limit. ServerModel prunes rows over 1 h old. */
function version_2(PDO $pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS matinee_attempts (
        user_id INTEGER NOT NULL,
        created_at INTEGER NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )');
    $pdo->exec('CREATE INDEX matinee_attempts_user_time_idx ON matinee_attempts(user_id, created_at)');
}

/**
 * P3 (C4): one matinee_transcodes row per long stream (an HLS session, or an mp3 transcode while it runs), and the
 * one-row lock that serializes every cap claim. Deleting a server or a user takes their rows with them.
 */
function version_3(PDO $pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS matinee_transcodes (
        id INTEGER PRIMARY KEY,
        server_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        session VARCHAR(36) NOT NULL UNIQUE,
        kind VARCHAR(8) NOT NULL,
        created_at INTEGER NOT NULL,
        last_seen_at INTEGER NOT NULL,
        stopped INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY(server_id) REFERENCES matinee_servers(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )');
    $pdo->exec('CREATE INDEX matinee_transcodes_stale_idx ON matinee_transcodes(stopped, last_seen_at)');
    $pdo->exec('CREATE INDEX matinee_transcodes_server_idx ON matinee_transcodes(server_id)');
    $pdo->exec('CREATE INDEX matinee_transcodes_user_idx ON matinee_transcodes(user_id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS matinee_locks (
        name VARCHAR(32) PRIMARY KEY,
        n INTEGER NOT NULL DEFAULT 0
    )');
    seed_streams_lock($pdo);
}

/** Seeds the `streams` lock row; running it again changes nothing. */
function seed_streams_lock(PDO $pdo)
{
    $pdo->exec("INSERT OR IGNORE INTO matinee_locks (name, n) VALUES ('streams', 0)");
}
