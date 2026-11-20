# Changelog

## Unreleased

Version stays 0.1.0 and is not tagged. This is the P1 foundation: servers and shares. Nothing plays yet.

### Added

- **Plex servers:** connect a server with a name, address and pasted token under your profile's **Plex
  servers**. Matinee checks the server's identity and libraries before it stores anything. Edit, verify and
  remove; a blank token keeps the stored one, and a changed address or machine id needs the token again.
- **Shares:** share the whole server or chosen libraries with a project you manage, or (admins) with everyone.
  Libraries are checked against the server's live list when the share is saved. Owners and admins unlink shares
  from the server page; the policy also lets the project's managers unlink, with no button for them yet.
- **SharePolicy:** one class decides every ability (connect, manage, edit, unlink, browse, rooms, playlists)
  from live user, role, project and server rows.
- **Admin settings (Settings → Matinee):** who may connect servers, server connection attempts per user per
  10 minutes (default 10), servers per user (default 3), private targets, allowed hosts, and every server
  with its owner, host, status and share count behind a disable/enable switch.
- **Schema:** `matinee_servers`, `matinee_shares`, `matinee_share_sections` and `matinee_attempts` on SQLite,
  MySQL, Postgres and SQL Server.
- **Missing extensions:** without sodium or curl, admins see a notice and nothing else runs.

### Security

- Tokens are sealed with XChaCha20-Poly1305 under a subkey of a root key from `MATINEE_KEY` or
  `DATA_DIR/matinee/secret.key`, bound to the server row, owner, address and machine id. The key file is
  created race-free at 0600 in a 0700 directory; symlinks, loose directories and wrong-size keys fail closed.
  Rows sealed under a non-active key are re-sealed on next use.
- The token is write-only and decrypted in one place. It travels to Plex only as a header and never reaches a
  page, flash message, log line or error.
- Outbound requests pass a URL guard on every call: private networks only through explicit private targets
  (CIDR floors /8 and /16, /104 over IPv4-in-IPv6 space, loopback only by IP), a 32400/443/80 port allow-list,
  embedded-IPv4 classification, an optional allowed-hosts list. Connections are pinned to the checked address,
  with no redirects, no proxy, verified TLS, timeouts and a 2 MiB body cap. Plex machine ids are pinned.
- Connect, verify and update fail with one generic message; the stage is logged server-side with the host only.
- Per-user attempt limit and per-user server cap.
- Every change is a CSRF-checked POST; actions authorize from the loaded row, never a URL project id; admin
  checks read the live users row. Matinee responses, refusals included, send `Referrer-Policy: same-origin`.

### Tests

- `./testing/run-plugin-tests.sh Matinee`: OK (668 tests, 5530 assertions).
- `./testing/run-plugin-tests.sh --all`: all 29 plugin suites pass ("All suites passed.").
