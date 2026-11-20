<?php
namespace Kanboard\Plugin\Matinee\Schema;

use PDO;

const VERSION = 1;

/**
 * Spec §1 P1 tables. Syntax follows core app/Schema/Mssql.php.
 * Untested: the dev stack and CI have no SQL Server. CREATE TABLE has no IF NOT EXISTS, following core
 * app/Schema/Mssql.php (T-SQL has no such clause; migrations run once per version anyway).
 * At most one everyone-share (project_id IS NULL) per server: UNIQUE(server_id, audience, project_id)
 * lets NULLs repeat, so a partial unique index (or, on MySQL, ShareModel) closes the gap.
 * matinee_shares.created_by deliberately has no FK: S6 treats a dangling creator as non-admin, and a cascade from
 * users would give Mssql a second cascade path to matinee_shares (users -> servers -> shares).
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
          , status nvarchar(16) NOT NULL DEFAULT \'ok\'
          , last_checked_at bigint NOT NULL DEFAULT 0
          , created_at bigint NOT NULL DEFAULT 0
          , updated_at bigint NOT NULL DEFAULT 0
        );
    ');
    $pdo->exec('CREATE INDEX matinee_servers_owner_idx ON dbo.matinee_servers(owner_user_id)');

    $pdo->exec('
        CREATE TABLE dbo.matinee_shares (
            id int identity PRIMARY KEY
          , server_id int NOT NULL REFERENCES dbo.matinee_servers(id) ON DELETE CASCADE
          , created_by int NOT NULL
          , audience nvarchar(16) NOT NULL
          , project_id int NULL REFERENCES dbo.projects(id) ON DELETE CASCADE
          , whole_server smallint NOT NULL DEFAULT 0
          , is_enabled smallint NOT NULL DEFAULT 1
          , created_at bigint NOT NULL DEFAULT 0
          , CONSTRAINT matinee_shares_audience_uq UNIQUE (server_id, audience, project_id)
        );
    ');
    $pdo->exec('CREATE INDEX matinee_shares_project_idx ON dbo.matinee_shares(project_id)');
    $pdo->exec('CREATE UNIQUE INDEX matinee_shares_everyone_uq ON dbo.matinee_shares(server_id) WHERE project_id IS NULL');

    $pdo->exec('
        CREATE TABLE dbo.matinee_share_sections (
            share_id int NOT NULL REFERENCES dbo.matinee_shares(id) ON DELETE CASCADE
          , section_key nvarchar(20) NOT NULL
          , PRIMARY KEY (share_id, section_key)
        );
    ');
}
