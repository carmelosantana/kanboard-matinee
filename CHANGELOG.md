# Changelog

## Unreleased

Version stays 0.1.0 and is not tagged. This is the P1 foundation: servers and the sharing switch. Nothing plays
yet.

**Light scope (2026-11-20).** Per-project shares are gone before they ever shipped. A server is private to its
owner or shared with every active Kanboard user, and a shared server is always the whole server. The
`matinee_shares` and `matinee_share_sections` tables, the share form (and its reusable-token link), project
audiences and project-role abilities were removed; schema v1 was edited in place, since it never shipped.

**P2 browse (2026-11-20).** Anyone who may browse a server can page through its libraries and see posters.
Nothing plays yet.

**Connecting by IP (2026-11-20).** `https://<Plex IP>:32400` now connects. It used to fail every time, because
Plex's `*.plex.direct` certificate never covers a bare IP.

- **Secure-name discovery:** for an `https://` address with an IPv4 host, Matinee makes a guarded TLS handshake
  with no token and no request (`CurlTransport::discoverPlexDirect`). The chain is verified, but the host name
  isn't checked on that handshake. If the leaf certificate is Plex's `*.<32 hex>.plex.direct` wildcard, the
  address becomes `https://<ip-dashed>.<hash>.plex.direct:<port>`. That name is guarded again and must pin to the
  typed IP. The rewritten address is stored, on connect and on an address change. A specific (non-wildcard)
  plex.direct name, a non-Plex certificate, an IPv6 address and an `http://` address are not rewritten.
- **Address help text** under the server address field, and one fixed hint added to the generic failure:
  "Check the address includes :32400 and the token is current."

### Added

- **Browse (Media):** a **Media** link in the dashboard sidebar lists the servers you may browse, then a
  server's libraries, then a library as a poster grid of 60 items a page. Shows, seasons, artists and albums
  open into their children. The breadcrumb names the server and library. A page past the end of a shrunken
  library links back to the first page.
- **Proxied posters (`matinee/art`):** 144 or 512 pixel posters fetched through Plex's photo transcoder,
  JPEG/PNG/WebP only, buffered under the 2 MiB cap.
- **RefSigner:** signed, user-bound, server- and library-scoped refs (HMAC-SHA256 under a `refs` subkey,
  base64url, at most one hour) for every listing and poster link.
- **PlexPaths:** the two anchored path shapes a ref may carry, shared by minting and use.
- **PlexClient::children() and art():** a whitelisted listing page and a poster fetch.
- **BrowseModel:** turns a verified ref into a listing or an image, re-authorizing on every use. A listing
  re-checks the live library list and that Plex's container is the ref's library.
- **Plex servers:** connect a server with a name, address and pasted token under your profile's **Plex
  servers**. Matinee checks the server's identity and libraries before it stores anything. Edit, verify and
  remove; a blank token keeps the stored one, and a changed address or machine id needs the token again.
- **Shared with everyone:** the owner's on/off switch on the server page (a CSRF-checked POST carrying the
  state wanted, so a repeat changes nothing). Shared means every active user may browse, use rooms and edit
  playlists once those phases land; the page says Plex will show all of that playback as the owner. Only the
  active owner of an enabled server can flip it; an admin can't flip someone else's.
- **MatineePolicy:** one class decides every ability (connect, manage, toggle sharing, browse, rooms,
  playlists) from the live user and server rows. Admins manage any server but browse only shared ones.
- **ServerModel::setShared and listBrowsable:** the switch, and the servers a user may browse (their own plus
  shared, enabled servers with an active owner), with public columns only.
- **Admin settings (Settings → Matinee):** who may connect servers, server connection attempts per user per
  10 minutes (default 10), servers per user (default 3), private targets, allowed hosts, and every server
  with its owner, host, status and shared flag behind a disable/enable switch.
- **Schema:** `matinee_servers` (with `is_shared`, default 0) and `matinee_attempts` on SQLite, MySQL, Postgres
  and SQL Server.
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
- Every change is a CSRF-checked POST; actions authorize from the live server row, never the caller's copy;
  admin checks read the live users row. Matinee responses, refusals included, send `Referrer-Policy: same-origin`.

- Browse refs are refused when tampered, expired, minted for another user, of the wrong kind or for a
  library no longer live; refusals are decided before any Plex request except the live-library and container
  checks. A server that is unshared, disabled or whose owner is deactivated stops browsing on the next click.
- Posters carry `nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`,
  `Cross-Origin-Resource-Policy: same-origin`, `Content-Disposition: inline` and `Cache-Control: private,
  max-age=3600`; refusals and failures are empty 403s and 502s with `no-store`. Plex titles have control and
  format characters stripped and are always escaped.
- A Plex JSON reply with more than 20,000 objects and arrays is refused before it is decoded, so a hostile
  2 MiB body can't exhaust PHP memory.

### Tests

- P2, after the whole-branch security gate (pass; no Critical, High or Medium):
  `./testing/run-plugin-tests.sh Matinee`: OK (901 tests, 7606 assertions). MariaDB and Postgres: 901 tests,
  7605 assertions, 1 skipped. `--all`: all suites pass.

- `./testing/run-plugin-tests.sh Matinee`: OK (584 tests, 4857 assertions) after the light scope.
- `./testing/run-realdb-tests.sh Matinee mariadb` and `postgres`: 584 tests, 4856 assertions, 1 skipped (the
  SQLite-only foreign-key pragma check).
- `./testing/run-plugin-tests.sh --all`: all 29 plugin suites pass ("All suites passed.").
