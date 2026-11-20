<?php
namespace Kanboard\Plugin\Matinee\Schema;

use PDO;

const VERSION = 2;

/**
 * Spec §1 P1 tables. Deleting a user, project or server takes its servers, shares and sections with it.
 * At most one everyone-share (project_id IS NULL) per server: UNIQUE(server_id, audience, project_id)
 * lets NULLs repeat, so a partial unique index (or, on MySQL, ShareModel) closes the gap.
 * matinee_shares.created_by deliberately has no FK: S6 treats a dangling creator as non-admin, and a cascade from
 * users would give Mssql a second cascade path to matinee_shares (users -> servers -> shares).
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
        status VARCHAR(16) NOT NULL DEFAULT \'ok\',
        last_checked_at BIGINT NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL DEFAULT 0,
        updated_at BIGINT NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE INDEX matinee_servers_owner_idx ON matinee_servers(owner_user_id)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS matinee_shares (
        id SERIAL PRIMARY KEY,
        server_id INTEGER NOT NULL REFERENCES matinee_servers(id) ON DELETE CASCADE,
        created_by INTEGER NOT NULL,
        audience VARCHAR(16) NOT NULL,
        project_id INTEGER NULL REFERENCES projects(id) ON DELETE CASCADE,
        whole_server SMALLINT NOT NULL DEFAULT 0,
        is_enabled SMALLINT NOT NULL DEFAULT 1,
        created_at BIGINT NOT NULL DEFAULT 0,
        UNIQUE(server_id, audience, project_id)
    )');
    $pdo->exec('CREATE INDEX matinee_shares_project_idx ON matinee_shares(project_id)');
    $pdo->exec('CREATE UNIQUE INDEX matinee_shares_everyone_uq ON matinee_shares(server_id) WHERE project_id IS NULL');

    $pdo->exec('CREATE TABLE IF NOT EXISTS matinee_share_sections (
        share_id INTEGER NOT NULL REFERENCES matinee_shares(id) ON DELETE CASCADE,
        section_key VARCHAR(20) NOT NULL,
        PRIMARY KEY(share_id, section_key)
    )');
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
