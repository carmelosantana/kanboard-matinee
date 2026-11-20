<?php
namespace Kanboard\Plugin\Matinee\Schema;

use PDO;

const VERSION = 2;

/**
 * Spec §1 P1 tables, light scope. Syntax follows core app/Schema/Mssql.php.
 * is_shared: 0 private (the owner only), 1 shared whole with every active user.
 * Untested: the dev stack and CI have no SQL Server. CREATE TABLE has no IF NOT EXISTS, following core
 * app/Schema/Mssql.php (T-SQL has no such clause; migrations run once per version anyway).
 */
function version_1(PDO $pdo)
{
    $pdo->exec('
        CREATE TABLE dbo.matinee_servers (
            id int identity PRIMARY KEY
          , owner_user_id int NOT NULL REFERENCES dbo.users(id) ON DELETE CASCADE
          , name nvarchar(100) NOT NULL
          , base_url nvarchar(255) NOT NULL
          , machine_id nvarchar(64) NOT NULL
          , token_nonce nvarchar(max) NOT NULL
          , token_ct nvarchar(max) NOT NULL
          , key_id nvarchar(16) NOT NULL
          , is_enabled smallint NOT NULL DEFAULT 1
          , is_shared smallint NOT NULL DEFAULT 0
          , status nvarchar(16) NOT NULL DEFAULT \'ok\'
          , last_checked_at bigint NOT NULL DEFAULT 0
          , created_at bigint NOT NULL DEFAULT 0
          , updated_at bigint NOT NULL DEFAULT 0
        );
    ');
    $pdo->exec('CREATE INDEX matinee_servers_owner_idx ON dbo.matinee_servers(owner_user_id)');
}

/** Spec §11 S1: one row per connect or verify attempt, for the per-user rate limit. ServerModel prunes rows over 1 h old. */
function version_2(PDO $pdo)
{
    $pdo->exec('
        CREATE TABLE dbo.matinee_attempts (
            user_id int NOT NULL REFERENCES dbo.users(id) ON DELETE CASCADE
          , created_at bigint NOT NULL
        );
    ');
    $pdo->exec('CREATE INDEX matinee_attempts_user_time_idx ON dbo.matinee_attempts(user_id, created_at)');
}
