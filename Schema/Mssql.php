<?php
namespace Kanboard\Plugin\Matinee\Schema;

use PDO;

const VERSION = 3;

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

/**
 * P3 (C4): one matinee_transcodes row per long stream (an HLS session, or an mp3 transcode while it runs), and the
 * one-row lock that serializes every cap claim. Deleting a server takes its rows with it.
 * Unlike the other drivers, user_id has no foreign key here: SQL Server refuses a second cascade path from users
 * (users -> matinee_servers -> matinee_transcodes and users -> matinee_transcodes), error 1785. A deleted user's rows on
 * someone else's server stop counting after 120 s, the sweep marks them stopped, and claim() prunes them a day later.
 */
function version_3(PDO $pdo)
{
    $pdo->exec('
        CREATE TABLE dbo.matinee_transcodes (
            id int identity PRIMARY KEY
          , server_id int NOT NULL REFERENCES dbo.matinee_servers(id) ON DELETE CASCADE
          , user_id int NOT NULL
          , session nvarchar(36) NOT NULL UNIQUE
          , kind nvarchar(8) NOT NULL
          , created_at bigint NOT NULL
          , last_seen_at bigint NOT NULL
          , stopped smallint NOT NULL DEFAULT 0
        );
    ');
    $pdo->exec('CREATE INDEX matinee_transcodes_stale_idx ON dbo.matinee_transcodes(stopped, last_seen_at)');
    $pdo->exec('CREATE INDEX matinee_transcodes_server_idx ON dbo.matinee_transcodes(server_id)');
    $pdo->exec('CREATE INDEX matinee_transcodes_user_idx ON dbo.matinee_transcodes(user_id)');
    $pdo->exec('
        CREATE TABLE dbo.matinee_locks (
            name nvarchar(32) NOT NULL PRIMARY KEY
          , n int NOT NULL DEFAULT 0
        );
    ');
    seed_streams_lock($pdo);
}

/** Seeds the `streams` lock row; running it again changes nothing. */
function seed_streams_lock(PDO $pdo)
{
    $pdo->exec("IF NOT EXISTS (SELECT 1 FROM dbo.matinee_locks WHERE name = 'streams') INSERT INTO dbo.matinee_locks (name, n) VALUES ('streams', 0)");
}
