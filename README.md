# Matinee (Kanboard plugin)

Connect a Plex server to Kanboard and share chosen libraries, or the whole server, with a project you manage.
The Plex token is encrypted at rest and never rendered.

**Status:** 0.1.0, in development (P1 foundation). Not released yet.

## Requirements

Kanboard `>=1.2.47` and PHP `>=8.4` with the `sodium` and `curl` extensions. Without either extension the
plugin only shows admins a notice and does nothing else.

Matinee adds three tables (`matinee_servers`, `matinee_shares`, `matinee_share_sections`) through core's
plugin migrations on SQLite, MySQL, Postgres and SQL Server.

## License

MIT
