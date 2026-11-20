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
        id INT NOT NULL AUTO_INCREMENT,
        owner_user_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        base_url VARCHAR(255) NOT NULL,
        machine_id VARCHAR(64) NOT NULL,
        token_nonce TEXT NOT NULL,
        token_ct TEXT NOT NULL,
        key_id VARCHAR(16) NOT NULL,
        is_enabled TINYINT NOT NULL DEFAULT 1,
        status VARCHAR(16) NOT NULL DEFAULT \'ok\',
        last_checked_at BIGINT NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL DEFAULT 0,
        updated_at BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY(id),
        INDEX matinee_servers_owner_idx (owner_user_id),
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $pdo->exec('CREATE TABLE IF NOT EXISTS matinee_shares (
        id INT NOT NULL AUTO_INCREMENT,
        server_id INT NOT NULL,
        created_by INT NOT NULL,
        audience VARCHAR(16) NOT NULL,
        project_id INT NULL,
        whole_server TINYINT NOT NULL DEFAULT 0,
        is_enabled TINYINT NOT NULL DEFAULT 1,
        created_at BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY(id),
        UNIQUE KEY matinee_shares_audience_uq (server_id, audience, project_id),
        INDEX matinee_shares_project_idx (project_id),
        FOREIGN KEY(server_id) REFERENCES matinee_servers(id) ON DELETE CASCADE,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    // MySQL has no partial unique index, so "at most one everyone-share per server" is enforced in code by ShareModel.

    $pdo->exec('CREATE TABLE IF NOT EXISTS matinee_share_sections (
        share_id INT NOT NULL,
        section_key VARCHAR(20) NOT NULL,
        PRIMARY KEY(share_id, section_key),
        FOREIGN KEY(share_id) REFERENCES matinee_shares(id) ON DELETE CASCADE
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
