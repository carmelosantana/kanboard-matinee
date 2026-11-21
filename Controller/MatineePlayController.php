<?php

namespace Kanboard\Plugin\Matinee\Controller;

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Matinee\Http\ResponseSink;
use Kanboard\Plugin\Matinee\Model\MatineeBusyException;
use Kanboard\Plugin\Matinee\Model\MatineeConnectException;
use Kanboard\Plugin\Matinee\Model\MatineeForbiddenException;
use Kanboard\Plugin\Matinee\Model\MatineeRangeException;
use Kanboard\Plugin\Matinee\Model\PlaybackModel;

/**
 * Playback (plan P3; spec §7, §11 S9, S11; C1b, C3d, C3e, C3f, C4c). Any signed-in user reaches it (ACL APP_USER);
 * PlaybackModel decides every request from the live rows and a signed ref.
 *
 * - `play` (C2c, C5b, C6): the player page in the dashboard layout. Audio gets an `<audio>` sourced from `media`; video
 *   gets a `<video>` whose claimed session, hls/start, ping, stop and keepalive URLs and reusable CSRF token travel in
 *   `data-` attributes for player.js (no inline script). Forbidden is core's 403; Busy is the busy page with a 503;
 *   Connect is P2's failure page with a 502.
 * - `media`: GET only. One window of a direct part, or an mp3 transcode, streamed through the ResponseSink, which alone
 *   opens the response (C3e). Once the ref and the server pass, the session is dropped without a write (C3d), and
 *   client aborts end the stream cleanly (C3f). A refusal is an empty body at 403, 405 (any other method, HEAD
 *   included, before the model runs), 416 (with `Content-Range: bytes * / N`), 502 or 503 (with `Retry-After: 30`),
 *   sent through Kanboard's response; once the sink has opened, nothing more is sent.
 * - `hlsStart` and `hlsProxy` (C2d, C5d): GET only, like media. Start answers Plex's master playlist for a session this
 *   user already holds; proxy answers a child playlist the same way, or streams a segment. A playlist goes through the
 *   ResponseSink too (open, then one write), as `application/vnd.apple.mpegurl` with the fixed set and no upstream
 *   header; every URI in it is a same-origin `url->to()` link back to hlsProxy. Refusals as for media.
 * - `hlsPing` and `hlsStop` (C2g, C6b): POST only, with the reusable CSRF token (the player's beacon can't fetch a
 *   fresh one) and the `session` field; the session is written as usual. An empty 204; a second stop is a 204 no-op.
 *   A bad token, or a session that isn't the caller's own (or, for ping, isn't live and browsable), is an empty 403.
 * - `keepalive`: POST only, with the reusable CSRF token. It only renews the session, which is written as usual:
 *   an empty 204. A bad or missing token or no signed-in user is an empty 403; another method an empty 405.
 */
class MatineePlayController extends MatineeBaseController
{
    /** GET matinee/play?ref=: the player page for a play ref from the listing. */
    public function play(): void
    {
        try {
            $page = $this->playback()->page($this->actorId(), $this->param('ref'));
        } catch (MatineeForbiddenException) {
            throw new AccessForbiddenException();
        } catch (MatineeBusyException) {
            $this->renderMedia('Matinee:play/busy', [], 503);
            return;
        } catch (MatineeConnectException) {
            $this->renderMedia('Matinee:browse/failed', [], 502);
            return;
        }
        $this->renderMedia('Matinee:play/player', ['page' => $page, 'csrf' => $this->token->getReusableCSRFToken()]);
    }

    /** GET matinee/media?ref= with an optional `Range` header. */
    public function media(): void
    {
        if (! $this->only('GET')) {
            return;
        }
        $ref = $this->param('ref');
        $range = $this->request->getHeader('Range');
        // C3f: stay alive past a client abort so the transfer stops cleanly and the claimed row is stopped.
        $this->ignoreUserAbort();
        $sink = $this->sink();
        $this->answer($sink, function () use ($ref, $range, $sink): void {
            $this->playback()->media($this->actorId(), $ref, $range === '' ? null : $range, $sink, $this->authorized());
        });
    }

    /** GET matinee/hls/start?ref=&session=: Plex's master playlist for a session this user holds, rewritten (C2d). */
    public function hlsStart(): void
    {
        if (! $this->only('GET')) {
            return;
        }
        $ref = $this->param('ref');
        $session = $this->param('session');
        $this->ignoreUserAbort();
        $sink = $this->sink();
        $this->answer($sink, function () use ($ref, $session, $sink): void {
            $this->playlist($sink, $this->playback()->hlsMaster($this->actorId(), $ref, $session, $this->proxyUrl($ref), $this->authorized()));
        });
    }

    /** GET matinee/hls/proxy?ref=&u=: a child playlist, rewritten like the master, or a segment streamed through. */
    public function hlsProxy(): void
    {
        if (! $this->only('GET')) {
            return;
        }
        $ref = $this->param('ref');
        $u = $this->param('u');
        $this->ignoreUserAbort();
        $sink = $this->sink();
        $this->answer($sink, function () use ($ref, $u, $sink): void {
            $body = $this->playback()->hlsChild($this->actorId(), $ref, $u, $sink, $this->proxyUrl($ref), $this->authorized());
            if ($body !== null) {
                $this->playlist($sink, $body);
            }
        });
    }

    /** POST matinee/hls/ping (session, reusable csrf_token): keeps the caller's own live session (C2g). */
    public function hlsPing(): void
    {
        $this->sessionPost(fn (string $session) => $this->playback()->ping($this->actorId(), $session));
    }

    /** POST matinee/hls/stop (session, reusable csrf_token): stops the caller's own session; again is a no-op (C6b). */
    public function hlsStop(): void
    {
        $this->sessionPost(fn (string $session) => $this->playback()->stop($this->actorId(), $session));
    }

    /** POST matinee/keepalive with the reusable csrf_token: renews the session (player pages, every 5 minutes). */
    public function keepalive(): void
    {
        if (! $this->only('POST')) {
            return;
        }
        try {
            $this->checkReusableCSRFParam();
        } catch (AccessForbiddenException) {
            $this->empty(403);
            return;
        }
        $this->empty($this->userSession->isLogged() ? 204 : 403);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** True for $method; anything else is answered with an empty 405 naming it (HEAD included). */
    private function only(string $method): bool
    {
        if ($this->request->getMethod() === $method) {
            return true;
        }
        $this->empty(405, ['Allow' => $method]);
        return false;
    }

    /**
     * Runs a model call and maps its refusals to empty answers: Forbidden 403, Busy 503 with `Retry-After: 30`, Range
     * 416 with `Content-Range: bytes * / N`, Connect 502. Nothing is sent once $sink has opened (C3g).
     */
    private function answer(?ResponseSink $sink, \Closure $run): void
    {
        try {
            $run();
        } catch (MatineeForbiddenException) {
            $this->refuse($sink, 403);
        } catch (MatineeBusyException) {
            $this->refuse($sink, 503, ['Retry-After' => '30']);
        } catch (MatineeRangeException $e) {
            $this->refuse($sink, 416, ['Content-Range' => 'bytes */'.$e->total]);
        } catch (MatineeConnectException) {
            $this->refuse($sink, 502);
        }
    }

    /** Ping and stop: POST with the reusable token (the player's beacon can't fetch a fresh one), then an empty 204. */
    private function sessionPost(\Closure $run): void
    {
        if (! $this->only('POST')) {
            return;
        }
        try {
            $this->checkReusableCSRFParam();
        } catch (AccessForbiddenException) {
            $this->empty(403);
            return;
        }
        $session = $this->field('session');
        $this->answer(null, function () use ($run, $session): void {
            $run($session);
            $this->empty(204);
        });
    }

    /** A rewritten playlist, sent through the sink so it carries the same fixed headers as a segment (C3e). */
    private function playlist(ResponseSink $sink, string $body): void
    {
        $sink->open(200, ['content-type' => 'application/vnd.apple.mpegurl']);
        $sink->write($body);
    }

    /** C2d: a rewritten URI is a same-origin Matinee URL; to() joins the query with a bare `&`. */
    private function proxyUrl(string $ref): \Closure
    {
        return fn (string $u): string => $this->helper->url->to('MatineePlayController', 'hlsProxy', ['plugin' => 'Matinee', 'ref' => $ref, 'u' => $u]);
    }

    /** The model runs this once the ref, the server and (for HLS) the session pass: the session is dropped (C3d). */
    private function authorized(): \Closure
    {
        return function (): void {
            $this->closeSession();
        };
    }

    /** C3f's seam: tests override it to record the order. */
    protected function ignoreUserAbort(): void
    {
        ignore_user_abort(true);
    }

    /**
     * C3d: ends the session without writing it, so a long stream can't write a stale session over newer tokens at the
     * end of the request. Tests override it to count.
     */
    protected function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
    }

    /** An empty refusal, unless the stream already opened the response. */
    private function refuse(?ResponseSink $sink, int $status, array $headers = []): void
    {
        if ($sink === null || ! $sink->opened()) {
            $this->empty($status, $headers);
        }
    }

    /** An empty response at $status, through Kanboard's response. */
    private function empty(int $status, array $headers = []): void
    {
        foreach ($headers + ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'same-origin'] as $name => $value) {
            $this->response->withHeader($name, $value);
        }
        $this->response->status($status);
    }

    private function sink(): ResponseSink
    {
        return $this->container['matineeResponseSink'];
    }

    private function playback(): PlaybackModel
    {
        return $this->container['matineePlaybackModel'];
    }
}
