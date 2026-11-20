<?php
namespace Kanboard\Plugin\Matinee\Schema;

use PDO;

const VERSION = 2;

/**
 * Spec §1 P1 tables, light scope. Deleting a user takes their servers with them.
 * is_shared: 0 private (the owner only), 1 shared whole with every active user.
 */
function version_1(PDO $pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS matinee_servers (
        id SERIAL PRIMARY KEY,
        owner_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        name VARCHAR(100) NOT NULL,
        base_url VARCHAR(255) NOT NULL,
        machine_id VARCHAR(64) NOT NULL,
        token_nonce TEXT NOT NULL,
        token_ct TEXT NOT NULL,
        key_id VARCHAR(16) NOT NULL,
        is_enabled SMALLINT NOT NULL DEFAULT 1,
        is_shared SMALLINT NOT NULL DEFAULT 0,
        status VARCHAR(16) NOT NULL DEFAULT \'ok\',
        last_checked_at BIGINT NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL DEFAULT 0,
        updated_at BIGINT NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE INDEX matinee_servers_owner_idx ON matinee_servers(owner_user_id)');
}

/** Spec §11 S1: one row per connect or verify attempt, for the per-user rate limit. ServerModel prunes rows over 1 h old. */
function version_2(PDO $pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS matinee_attempts (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        created_at BIGINT NOT NULL
    )');
    $pdo->exec('CREATE INDEX matinee_attempts_user_time_idx ON matinee_attempts(user_id, created_at)');
}
