# Matinee (Kanboard plugin)

Connect a Plex server to Kanboard and share chosen libraries, or the whole server, with a project you manage.
The Plex token is encrypted at rest and never rendered.

**Status:** 0.1.0, in development (P1 foundation). Not released yet.

## Requirements

Kanboard `>=1.2.47` and PHP `>=8.4` with the `sodium` and `curl` extensions. Without either extension the
plugin only shows admins a notice and does nothing else.

Matinee adds three tables (`matinee_servers`, `matinee_shares`, `matinee_share_sections`) through core's
plugin migrations on SQLite, MySQL, Postgres and SQL Server.

## Key file

Plex tokens are sealed with a 32-byte root key.

- Set `MATINEE_KEY` to supply it yourself. Generate one with `openssl rand -base64 32` (padded standard
  base64). A malformed value fails closed.
- Without `MATINEE_KEY`, Matinee creates the key on first use at `DATA_DIR/matinee/secret.key` (0600, in a
  0700 directory). A key directory that is a symlink or writable by group or others is refused, and so is a
  symlinked key file.
- Back the key up. On SQLite a backup of `data/` holds both the key and the sealed tokens, so protect it
  like the key itself.
- A truncated or otherwise wrong-size key file fails closed and is never replaced. To recover, restore it
  from backup, or delete it and have owners reconnect their servers.

## License

MIT
