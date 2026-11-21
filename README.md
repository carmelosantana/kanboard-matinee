# Matinee (Kanboard plugin)

Connect a Plex server to Kanboard and keep it to yourself, or share the whole server with everyone on your
Kanboard. The Plex token is encrypted at rest and never shown to anyone, admins included.

**Status:** 0.1.0, in development (P1 foundation, P2 browse and P3 playback). Not released or listed in ModMenu yet.

## What it does today

P1 is the foundation: servers and the sharing switch. P2 adds browsing. P3 adds playback.

- **Connect a Plex server** with a name, its address and your Plex token. Matinee checks the server answers
  and the token works before it stores anything.
- **Share it, or don't** (see [Sharing](#sharing)). A server is private to you until you switch on **Shared
  with everyone**.
- **Manage it.** Verify the connection, change the name, address or token, remove the server. Admins see
  every server and can switch any of them off.
- **Browse it** (see [Browsing](#browsing)). **Media** on the dashboard lists the servers you may browse, their
  libraries, and each library's posters.
- **Play it** (see [Playing](#playing)). Movies, episodes and music tracks play in the browser, under stream
  caps an admin sets.

Watch-together rooms and playlists come in later phases (see [Roadmap](#roadmap)).

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

- **Server address.** Type `https://<Plex IP>:32400` (for example `https://192.168.1.86:32400`) or your
  plex.direct address. Plex listens on port 32400. The address is scheme, host and port only: no path, query or
  `user:pass@`.

  Plex's certificate is a `*.<hash>.plex.direct` wildcard, which never covers a bare IP, so the IP form can't
  pass TLS checks by itself. For an `https://` address with an IPv4 host, Matinee finds the secure name for you.
  It makes a TLS handshake with no token and sends no request. The certificate chain must be trusted, but the
  host name isn't checked on this one handshake. If the certificate is Plex's wildcard, Matinee uses
  `https://<ip-dashed>.<hash>.plex.direct:<port>`: the hash comes from the certificate, and the IP and port are
  the ones you typed. That name is checked against the private targets again, must resolve to the same IP, and
  is then verified in full before the token is sent. The plex.direct address is what gets stored, and it's what
  you see on the server page. IPv6 addresses and servers without a Plex certificate are used as typed.

  A plain `http://192.168.1.10:32400` works too when an admin has added that address as a private target, but
  it fails when Plex's **Secure connections** setting is **Required**.
- **Plex token.** Follow Plex's support article "Finding an authentication token" (open any item in Plex Web,
  choose **View XML**, and copy the `X-Plex-Token` value from the address bar).

The token field is write-only. When you edit a server, leave it blank to keep the stored token. Changing the
address needs the token again, and so does a server that now reports a different machine id.

When connecting fails, the message is always the same: "Could not connect to that server with that token.
Check the address includes :32400 and the token is current." That covers a refused address, an unreachable
server, a certificate that isn't Plex's and a rejected token alike, so the form can't be used to probe your
network. The reason goes to the Kanboard log (stage and host only; a failed secure-name lookup logs stage
`discover`).

A plex.direct name resolves to the IP in its first label, whatever the hash. If that IP is private, the server
counts as private and needs a private target like any other LAN address.

## Sharing

A server is either **private** (only you) or **shared with everyone**: every active user on this Kanboard.
Sharing is always the whole server, every library whose type Matinee plays (movies, shows, music, photos),
including libraries you add later. There are no per-project or per-library shares.

Open the server under **Plex servers** and use **Share with everyone** or **Make private**. Only you, the
owner, can flip it, and only while the server is enabled. An admin can't share or unshare someone else's
server, but can disable it.

Everyone you share with browses and plays through your Plex. Plex counts that playback against your account,
and names each viewer's device `Matinee (<username>)`.

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

## Playing

A **Play** link sits on every movie, episode and track in a listing. It opens the player page inside Kanboard.
The page re-checks that you may browse the server, that the library is still live and that the item belongs to
it.

### Music

A track plays straight from its file (direct play) when its container and codec are one of these pairs and it
has no video stream:

| Container | Audio codec | Sent as |
|---|---|---|
| `mp3` | `mp3` | `audio/mpeg` |
| `flac` | `flac` | `audio/flac` |
| `mp4` or `m4a` | `aac` | `audio/mp4` |

All three play in Chrome, Firefox and Safari. Every other track (ALAC, Ogg, Opus, WMA, WAV and the rest)
plays as a 320 kbps mp3 transcode made by Plex.

- **Direct play is sent in windows.** Each request answers `206 Partial Content` with at most 8 MiB, so seeking
  works and no request holds a worker for long. A request without a usable `Range` gets the first 8 MiB.
- **A transcoded track can't seek.** It is one `200` response with `Accept-Ranges: none`, capped at 15 minutes
  and 64 MiB. A pause longer than about a minute ends it (see [Web server](#web-server)), and the player then
  shows "This item could not be played." Start the track again from the listing.

### Video

Every movie and episode plays over HLS through Plex's transcoder, at up to 1080p and 8 Mbps, with subtitles
burned in. Matinee asks Plex to direct-stream video and audio when it can, so a compatible file is remuxed
rather than re-encoded.

The player uses the vendored hls.js (see [Vendored files](#vendored-files)). A browser without Media Source
Extensions but with native HLS, such as Safari on iOS, plays the stream natively. Any other browser shows "Your
browser cannot play this video."

Every playlist Matinee passes on is rewritten so each address in it points back to Matinee, never to Plex. A
playlist that carries anything unexpected (an unknown tag, a URL outside a `URI` attribute, non-ASCII text) is
refused whole.

### The player page

- **Ping.** A video page pings Matinee every 30 seconds, so a paused film keeps its slot. If a ping is refused
  (the session was stopped, or the server is no longer yours to browse), the page reloads and claims a fresh
  session. A second refusal within two minutes stops trying and shows the error instead of looping.
- **Keepalive.** Every player page, audio included, renews your Kanboard session every 5 minutes, so a long
  film or album doesn't sign you out.
- **Stop on leave.** Leaving or reloading the page sends one stop beacon. Matinee marks the session stopped and
  asks Plex to end the transcode. A fatal player error or a media error stops the session the same way, so a
  broken player never holds a slot. A page restored with the browser's Back button reloads, because its
  session was stopped when you left.
- **Sweep.** A session that hasn't been seen for 120 seconds (a closed laptop, a crashed tab) no longer counts
  against any cap. Matinee stops such sessions lazily, at most two at a time: whenever a video page opens, and
  on about one Matinee page in ten.

Play links are signed for you only and last 8 hours, so a film can be paused and resumed within an evening.

### Stream caps

A long stream is a video session, or a transcoded track while it plays. Direct-play music windows are short
requests and don't count. Each long stream counts against three caps:

| Cap | Default | Setting |
|---|---|---|
| Per server | 4 | Long streams per server |
| Per user | 2 | Long streams per user |
| On this Kanboard | 5 | Long streams on this Kanboard |

Each is a whole number from 1 to 1000, set under [Admin settings](#admin-settings). Claims are serialized, so
concurrent requests can't pass a cap.

When a cap is reached, the player page answers `503` with `Retry-After: 30` and reads "This server is busy
right now. Try again in a minute." A media or HLS request over a cap is an empty `503` with `Retry-After: 30`.
Opening the next film in the same tab at a per-user cap of 1 can beat the previous page's stop beacon and get
the busy page; reloading fixes it.

## Web server

Each long stream holds one PHP-FPM worker for as long as Plex is sending. Three settings keep that bounded:

- **Keep nginx's `fastcgi_buffering` on** (the default). When a listener pauses a transcoded track, nginx keeps
  reading from PHP into a temp file, so the worker is freed once Plex finishes rather than held by a paused
  browser. Matinee never sends `X-Accel-Buffering: no`. Without buffering, a paused transcode holds its worker
  until the 15-minute cap.
- **Set PHP-FPM's `request_terminate_timeout` to 900 s or more**, or leave it at 0. A shorter value cuts a
  transcoded track before its own 15-minute cap.
- **Keep the global stream cap under a quarter of `pm.max_children`.** Every long stream pins a worker, and the
  rest of Kanboard needs the others. The default global cap of 5 wants `pm.max_children` of 21 or more; lower
  the cap if your pool is smaller.

## Admin settings

**Settings → Matinee** (admins only).

| Setting | Default | What it does |
|---|---|---|
| Who may connect a Plex server | Every user | Every active user, or administrators only |
| Server connection attempts per user per 10 minutes | 10 | Connect, verify and saving an edit each count one |
| Servers per user | 3 | How many servers one user may own |
| Long streams per server | 4 | Video sessions and transcoded tracks one server may run at once (see [Stream caps](#stream-caps)) |
| Long streams per user | 2 | Long streams one user may run at once, across every server |
| Long streams on this Kanboard | 5 | Long streams across every server and user; keep it under a quarter of `pm.max_children` |
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
| `matinee_transcodes` | one row per long stream: server, user, session id, kind (`hls` or `mp3`), last seen, stopped flag; stopped rows are deleted after a day |
| `matinee_locks` | one row, `streams`, that serializes stream claims |

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
  the only Kanboard data sent to Plex. During playback the name is the viewer's, not the owner's.
- **Playback refs.** Play, media and HLS links are signed the same way and last up to 8 hours. Every media,
  playlist and segment request re-checks the signature, the user, the expiry and that you may still browse the
  server. An HLS request must also name a live session of your own on that server. Only Plex item, part and
  transcode paths can be reached.
- **Streams.** Media, playlists and segments are sent with `nosniff`, `Content-Security-Policy: default-src
  'none'; sandbox`, `Content-Disposition: inline`, `Cross-Origin-Resource-Policy: same-origin` and
  `Cache-Control: private, no-store`. Only a known content type for each kind is passed on; anything else is
  an empty 502. Media endpoints answer GET only. A stream request drops the Kanboard session without writing it
  first, so a long stream can't overwrite newer session data when it ends.
- **The player page** alone widens the Content Security Policy, adding `media-src 'self' blob:` and
  `worker-src 'self' blob:` for hls.js. `script-src` is never touched, and hls.js and the player script load on
  that page only.
- **Client aborts (a deviation from the spec).** The spec's S3 asks for `ignore_user_abort(false)`. Matinee sets
  `ignore_user_abort(true)` for the stream window instead. With `false`, PHP bails out on the first write after
  the browser goes away, `finally` blocks never run, and the stream's cap row leaks until the sweep finds it.
  With `true`, the response sink notices the abort itself, curl stops cleanly, and the row is stopped at once.
  A shutdown function stops any row the request claimed as a backstop. The cost is that a worker may read one
  more chunk from Plex after the browser leaves.

## Roles

What each role can do with a server. Browsing and playing work today; rooms and playlists arrive with P4–P5. Project roles play no part.

| Who | Can |
|---|---|
| Server owner | connect, edit, verify and remove their servers; share or unshare them; browse, play, start or control rooms and edit playlists on them |
| Everyone, when the server is shared | every active user: browse, play, start or control rooms and edit playlists |
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
- **The owner's Plex carries all traffic.** Plex shows each playing viewer as "Matinee (username)", so the
  owner can see who watched what. All viewers share one Plex client id per install; Plex tells their sessions
  apart by session id.
- **Transcoding load lands on the owner's Plex.** Every video is an HLS transcode or remux, and off-list music
  is an mp3 transcode. The [stream caps](#stream-caps) bound how many run at once.
- **All media bandwidth flows through Kanboard.** Every byte is proxied, so the Kanboard host's upload is the
  ceiling for everyone playing at once.
- **Segment requests within a session aren't limited.** A viewer holding one session can fetch its playlists
  and segments as fast as they like; only the number of sessions is capped.
- **A transcoded track can't seek or resume after a long pause** (see [Music](#music)).
- **Browsing has no rate limit.** Each library page costs Plex two requests and each poster one, for anyone
  the server is shared with. A user who keeps reloading adds load to the owner's Plex (never more than one
  Plex call per Matinee request beyond those). Keep a server private if you don't trust everyone on the board.
- **A poster stays in a viewer's browser cache for up to an hour** after the server is made private.

## Roadmap

| Phase | Adds |
|---|---|
| P1 (done) | servers, the sharing switch, policy, settings |
| P2 (done) | signed media refs, browse pages, artwork |
| P3 (done) | playback: audio with ranged responses, video through Plex HLS transcode, stream caps |
| P4 | watch-together rooms, synced to within about 2 s |
| P5 | playlists per server (Matinee's own; nothing is written to Plex) |

Matinee never writes to the owner's Plex account: no playlists, watch state or ratings.

## Uninstall

Removing `plugins/Matinee/` stops the plugin but deletes none of its data. What stays behind:

| Left behind | How to delete it |
|---|---|
| Tables `matinee_servers`, `matinee_attempts`, `matinee_transcodes` and `matinee_locks` | `DROP TABLE` each |
| Matinee's row in core's `plugin_schema_versions` table (`plugin` = `matinee`) | delete it too, or a reinstall skips creating the tables |
| Rows in core's `settings` table whose `option` starts with `matinee_` | delete those rows (the `option` column is a reserved word on MySQL, so quote it there) |
| The key file `DATA_DIR/matinee/secret.key` | delete the `DATA_DIR/matinee/` directory |
| `MATINEE_KEY`, if you set it | remove it from the environment or the php-fpm pool config |

The key and the database together still decrypt every stored token, so delete both, including copies in
backups you keep. Owners should also revoke their tokens in Plex: signing out of all devices, or changing the
Plex password, invalidates existing tokens.

## Vendored files

Matinee ships one third-party file, copied unmodified from the npm tarball after a supply-chain check. Nothing
from the package was installed or run. `Test/VendorTest.php` checks the file against the sha256 in this table.

| File | Version | Source tarball | npm integrity | sha256 | Vendored |
|---|---|---|---|---|---|
| `Assets/vendor/hls.js/hls.min.js` | 1.7.1 | `https://registry.npmjs.org/hls.js/-/hls.js-1.7.1.tgz` | `sha512-DlzIkeBAS9IIQ432k3BUf3HlwbsR0+trB1i2lDdN2gUkNkrehFurh0/48M5c1/EjlDkdGng1gwZIpwyPxvdZ/g==` | `6cfad701a61fb8a99add5e84449e64661169b0652bf44ceb2a28465c8817b5f1` | 2026-11-21 |

To check the file yourself, run `sha256sum Assets/vendor/hls.js/hls.min.js` and compare it with the table.

hls.js is licensed under Apache-2.0; its license is at `Assets/vendor/hls.js/LICENSE`.

## Tests

From the harness checkout (the directory above this plugin):

```sh
./testing/run-plugin-tests.sh Matinee
```

That runs the PHPUnit suite on SQLite. It includes `Test/PlayerJsTest.php`, which runs the player's node tests
when `node` is on `PATH` and skips them otherwise. To run the node tests alone, from this directory:

```sh
node --test Test/js/player.test.mjs
```

Name the file. The directory form, `node --test Test/js`, fails on Node 22.

## License

MIT
