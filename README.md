# Matinee (Kanboard plugin)

Connect a Plex server to Kanboard and keep it to yourself, or share the whole server with everyone on your
Kanboard. The Plex token is encrypted at rest and never shown to anyone, admins included.

**Status:** 0.1.0, in development (P1 foundation and P2 browse). Not released or listed in ModMenu yet.

## What it does today

P1 is the foundation: servers and the sharing switch. P2 adds browsing. Nothing plays yet.

- **Connect a Plex server** with a name, its address and your Plex token. Matinee checks the server answers
  and the token works before it stores anything.
- **Share it, or don't** (see [Sharing](#sharing)). A server is private to you until you switch on **Shared
  with everyone**.
- **Manage it.** Verify the connection, change the name, address or token, remove the server. Admins see
  every server and can switch any of them off.
- **Browse it** (see [Browsing](#browsing)). **Media** on the dashboard lists the servers you may browse, their
  libraries, and each library's posters.

Playing media, watch-together rooms and playlists come in later phases (see [Roadmap](#roadmap)).

## Requirements

- Kanboard `>=1.2.47`
- PHP `>=8.4` with the `sodium` and `curl` extensions. Without either one Matinee shows admins a notice and
  does nothing else.

No other plugin is needed. Nothing is installed on the Plex server, and viewers need no Plex account.

## Install

Matinee is not released yet. To try it, copy the plugin folder (or a zip of it) into `plugins/Matinee/` so that
`plugins/Matinee/Plugin.php` exists, then reload Kanboard. Core's plugin migrations create the tables on
SQLite, MySQL, Postgres and SQL Server.

## Setup cost

| Who | Step | Cost |
|---|---|---|
| Admin | Install the plugin (manual zip for now, ModMenu once listed). The PHP image already has sodium and curl. | 1 min |
| Admin | If Plex is on a private network, add it under **Private targets** (see [Admin settings](#admin-settings)). | 1 min |
| Admin | **Back up the key file** (`DATA_DIR/matinee/secret.key`), or set `MATINEE_KEY`. Losing it means every owner pastes their token again. | one-time chore |
| Owner | Find the server address. | about 5 min the first time |
| Owner | Find the Plex token and paste it. | about 3 min |
| Owner | Switch on **Shared with everyone**, if you want to. | one click |

## Connecting a server

Open your profile, then **Plex servers → Add a Plex server**.

- **Server address.** Prefer the `https://<ip-dashed>.<hash>.plex.direct:32400` form. Find it in Plex Web under
  Settings → Network, or in your plex.tv resources list. A plain `http://192.168.1.10:32400` works too when an
  admin has added that address as a private target. The address is scheme, host and port only: no path, query
  or `user:pass@`.
- **Plex token.** Follow Plex's support article "Finding an authentication token" (open any item in Plex Web,
  choose **View XML**, and copy the `X-Plex-Token` value from the address bar).

The token field is write-only. When you edit a server, leave it blank to keep the stored token. Changing the
address needs the token again, and so does a server that now reports a different machine id.

When connecting fails, the message is always the same: "Could not connect to that server with that token."
That covers a refused address, an unreachable server and a rejected token alike, so the form can't be used to
probe your network. The reason goes to the Kanboard log (stage and host only).

A plex.direct name resolves to the IP in its first label, whatever the hash. If that IP is private, the server
counts as private and needs a private target like any other LAN address.

## Sharing

A server is either **private** (only you) or **shared with everyone**: every active user on this Kanboard.
Sharing is always the whole server, every library whose type Matinee plays (movies, shows, music, photos),
including libraries you add later. There are no per-project or per-library shares.

Open the server under **Plex servers** and use **Share with everyone** or **Make private**. Only you, the
owner, can flip it, and only while the server is enabled. An admin can't share or unshare someone else's
server, but can disable it.

Once playback ships, everyone you share with browses and plays through your Plex, and Plex shows all of that
playback as you.

## Browsing

Open **Media** in the dashboard sidebar. It lists every server you may browse: your own, plus every enabled
server that is shared and whose owner is active. Pick a server to see its libraries (movies, shows, music,
photos), then a library to see its items as a poster grid. Shows open into seasons and episodes, artists into
albums and tracks.

- **Paging.** 60 items a page, with Previous and Next links. A link past the end of a library that has shrunk
  shows "Nothing to show here." and a link back to the first page.
- **Posters are proxied.** Every image comes from Matinee, never from Plex directly, so the browser never sees
  the Plex address or token. Posters come in two sizes (144 and 512 pixels), only as JPEG, PNG or WebP, and
  the browser may cache them privately for an hour.
- **Links are signed and personal.** Every library, item and poster link carries a signed reference that works
  for you only and expires after an hour. Each use re-checks that you may still browse the server, so making a
  server private, disabling it or deactivating a user cuts browsing off on the next click. Opening a library
  also re-checks that Plex still has it.
- **Failures** read "Could not reach that server." whatever went wrong; the stage goes to the Kanboard log.

Admins browse only shared servers, like everyone else; an admin can't browse someone's private server.

## Admin settings

**Settings → Matinee** (admins only).

| Setting | Default | What it does |
|---|---|---|
| Who may connect a Plex server | Every user | Every active user, or administrators only |
| Server connection attempts per user per 10 minutes | 10 | Connect, verify and saving an edit each count one |
| Servers per user | 3 | How many servers one user may own |
| Private targets | empty | Private addresses Matinee may reach (below) |
| Allowed hosts | empty | When set, the only hosts Matinee may reach at all |

Below the settings, the server list shows every server with its owner, host, status and whether it is
shared, and a **Disable / Enable** switch. A disabled server hands out no token, so no one can use it, the
owner included. An admin can open any server to verify or remove it. There is no edit, no sharing switch and no
token here.

### Private targets

Public addresses are reachable on ports 32400, 443 and 80. Private ones (RFC 1918, loopback, CGNAT,
`198.18.0.0/15`, IPv6 ULA) are refused unless an entry matches. There is no blanket "allow LAN" switch.

One entry per line: a host name, IP address or CIDR range, with an optional `:port`.

| Entry | Admits |
|---|---|
| `192.168.1.10:32400` | that address on port 32400 only |
| `192.168.1.10` | that address on 32400, 443 or 80 |
| `10.0.0.0/8` | the whole range on the default ports |
| `plex.lan` | whatever private address `plex.lan` resolves to, except loopback |
| `127.0.0.1:32400` | Plex on the Kanboard host itself |
| `[fd12:3456::/48]:32400` | an IPv6 range on port 32400 (bracket IPv6 when a port follows) |

Rules:

- An entry that names a port allows that port only. Ports have one spelling: no sign, no leading zero.
- CIDR ranges must be at least `/8` for IPv4 and `/16` for IPv6. A shorter one is refused on save.
- A host-name entry trusts that name's DNS, but never for loopback. Loopback needs an IP or CIDR entry.
- IPv6 forms that carry an IPv4 address (mapped `::ffff:0:0/96`, SIIT, NAT64 `64:ff9b::/96`) are judged by that
  IPv4, so an IPv4 entry covers them. An IPv6 entry over that space must be `/104` or longer. A 6to4 address
  (`2002::/16`) needs an IPv6 entry.
- Some addresses are refused whatever the list says: link-local, cloud metadata (`169.254.169.254`,
  `100.100.100.200`), unspecified, multicast, reserved and site-local.

### Allowed hosts

Empty means any host that passes the checks above. Once set, only listed hosts are reachable: exact host
names or IPs, or `*.domain` for a suffix, e.g. `*.plex.direct`. A private host has to pass both lists.

## The key file

Plex tokens are sealed with a 32-byte root key.

- **Set `MATINEE_KEY`** to supply it yourself (recommended). Generate one with `openssl rand -base64 32`
  (padded standard base64). A malformed value fails closed and leaves every server's status as it was.
- **Under php-fpm**, `clear_env` (on by default) hides the process environment from PHP. Pass the key through
  the pool config instead, e.g. `env[MATINEE_KEY] = <base64 key>` in the pool's `.conf`, and keep that file
  readable by root only.
- **Without `MATINEE_KEY`**, Matinee creates the key on first use at `DATA_DIR/matinee/secret.key` (0600, in a
  0700 directory). A key directory that is a symlink or writable by group or others is refused, and so is a
  symlinked key file.
- **Back the key up.** On SQLite a backup of `data/` holds both the key and the sealed tokens, so protect it
  like the key itself, or keep the key out of that volume with `MATINEE_KEY`.
- **A truncated or wrong-size key file** fails closed and is never replaced. To recover, restore it from
  backup. If it is gone, delete it: Matinee makes a new key on next use. Existing tokens can't be opened any
  more, so each server shows "Needs the token again" once it is verified or used, and each owner reconnects by
  pasting their token on the server's edit page.

Moving from the key file to `MATINEE_KEY` (or back) is safe while both keys are present: a token sealed under
the other key still opens and is re-sealed under the active one on next use.

**Admin settings → Matinee** shows where the active key comes from (the env var or the key file) and its
8-character key id. When the key can't be loaded, it shows one fixed notice instead. The notice never
includes key bytes or paths.

## What is stored and where

| Table | Holds |
|---|---|
| `matinee_servers` | owner, name, base address, Plex machine id, sealed token, key id, enabled flag, shared flag, status, timestamps |
| `matinee_attempts` | one row per server connection attempt, for the rate limit; pruned after an hour |

Settings live in core's `settings` table under `matinee_*` keys, including a per-install Plex client id.

The token is sealed with XChaCha20-Poly1305 under a subkey of the root key, with a fresh 24-byte nonce each
time. The seal is bound to the server row, its owner, its address and its machine id, so a token can't be
moved to another row or endpoint. Only the ciphertext and nonce are stored. No page, flash message, log line or
error ever contains the token, and there is no "show token", not even for admins.

Deleting a user removes their servers.

## Security model

- **Token.** Sent to Plex only as the `X-Plex-Token` header, never in a URL. Decrypted in one place, only
  for an enabled server whose owner is active. Sensitive parameters are hidden from stack traces.
- **Outbound requests.** Every request is checked by the URL guard (scheme, host, port, private targets,
  allowed hosts), then pinned to the address that passed, so a DNS answer can't change in between. Redirects
  are not followed, proxies are ignored, TLS is verified, timeouts are short and bodies are capped at 2 MiB.
  Matinee pins each server's Plex machine id and refuses a server that has changed.
- **Failures** read the same to the user whatever went wrong (see [Connecting a server](#connecting-a-server)).
- **Rate limits.** Server connection attempts per user per 10 minutes, and servers per user.
- **Live checks.** Every ability is decided from the database on each request: the user and the server's
  owner must be active, the server enabled, and the server shared or yours; an admin must still be an admin.
  Nothing is cached across requests, so making a server private, disabling it or deactivating a user takes
  effect on the next click.
- **Authorization from the object.** Actions load the server row and decide from it, never from the caller's
  copy or anything else in the URL.
- **Forms.** Every change is a POST with Kanboard's CSRF token. Anonymous users reach no Matinee page.
- **Headers.** Matinee pages send `Referrer-Policy: same-origin`, refusals included. Posters are sent with
  `nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`, `Cross-Origin-Resource-Policy: same-origin`
  and `Cache-Control: private`; a refused or failed poster is an empty 403 or 502.
- **Signed references.** Browse and poster links are HMAC-SHA256 signed with a subkey of the root key, bound
  to the viewer, the server and the library, and valid for at most an hour. Only two anchored Plex path shapes
  can be signed or followed (a library or item listing, a poster), so a reference can't steer Matinee to any
  other Plex endpoint.
- **Plex sees one name per viewer.** Requests carry `X-Plex-Device-Name: Matinee (<username>)`. The username is
  the only Kanboard data sent to Plex.

## Roles

What each role can do with a server. Browsing works today; rooms and playlists arrive with P4–P5. Project roles play no part.

| Who | Can |
|---|---|
| Server owner | connect, edit, verify and remove their servers; share or unshare them; browse, start or control rooms and edit playlists on them |
| Everyone, when the server is shared | every active user: browse, start or control rooms and edit playlists |
| App admin | see every server; verify, disable or remove any of them. No browse of a private server, and no sharing switch on someone else's. |

Connecting a server follows **Who may connect a Plex server**. Nothing works on a disabled server or on a
server whose owner is deactivated.

## Known limits and accepted risks

- **The key file plus the database reveal every token.** That is the same trust boundary as Kanboard's
  `config.php`. Keep backups that hold both as safe as the key.
- **Limits are checked without a lock.** A burst of concurrent requests can pass the attempt limit or the
  server cap by a little. An owner can't add the same host and port twice.
- **Sharing is all or nothing.** A shared server reaches every active user, including users added later, and
  every library the owner adds later. Keep a server private if that is too wide.
- **The owner's Plex sees all traffic as the owner.** Plex activity shows "Matinee (username)", so the owner
  can see who watched what.
- **Transcoding load** will land on the owner's Plex (P3 adds stream caps).
- **Browsing has no rate limit.** Each library page costs Plex two requests and each poster one, for anyone
  the server is shared with. A user who keeps reloading adds load to the owner's Plex (never more than one
  Plex call per Matinee request beyond those). Keep a server private if you don't trust everyone on the board.
- **A poster stays in a viewer's browser cache for up to an hour** after the server is made private.

## Roadmap

| Phase | Adds |
|---|---|
| P1 (done) | servers, the sharing switch, policy, settings |
| P2 (done) | signed media refs, browse pages, artwork |
| P3 | playback: audio with ranged responses, video through Plex HLS transcode, stream caps |
| P4 | watch-together rooms, synced to within about 2 s |
| P5 | playlists per server (Matinee's own; nothing is written to Plex) |

Matinee never writes to the owner's Plex account: no playlists, watch state or ratings.

## Uninstall

Removing `plugins/Matinee/` stops the plugin but deletes none of its data. What stays behind:

| Left behind | How to delete it |
|---|---|
| Tables `matinee_servers` and `matinee_attempts` | `DROP TABLE` each |
| Matinee's row in core's `plugin_schema_versions` table (`plugin` = `matinee`) | delete it too, or a reinstall skips creating the tables |
| Rows in core's `settings` table whose `option` starts with `matinee_` | delete those rows (the `option` column is a reserved word on MySQL, so quote it there) |
| The key file `DATA_DIR/matinee/secret.key` | delete the `DATA_DIR/matinee/` directory |
| `MATINEE_KEY`, if you set it | remove it from the environment or the php-fpm pool config |

The key and the database together still decrypt every stored token, so delete both, including copies in
backups you keep. Owners should also revoke their tokens in Plex: signing out of all devices, or changing the
Plex password, invalidates existing tokens.

## License

MIT
