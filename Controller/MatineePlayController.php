<?php

namespace Kanboard\Plugin\Matinee\Controller;

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
 * - `media`: one window of a direct part, or an mp3 transcode, streamed through the ResponseSink, which alone opens
 *   the response (C3e). Once the ref and the server pass, the session is dropped without a write (C3d), and client
 *   aborts end the stream cleanly (C3f). A refusal is an empty body at 403, 416 (with `Content-Range: bytes * / N`),
 *   502 or 503 (with `Retry-After: 30`), sent through Kanboard's response; once the sink has opened, nothing more is
 *   sent.
 */
class MatineePlayController extends MatineeBaseController
{
    /** GET matinee/media?ref= with an optional `Range` header. */
    public function media(): void
    {
        $ref = $this->param('ref');
        $range = $this->request->getHeader('Range');
        // C3f: stay alive past a client abort so the transfer stops cleanly and the claimed row is stopped.
        ignore_user_abort(true);
        $sink = $this->sink();
        try {
            $this->playback()->media($this->actorId(), $ref, $range === '' ? null : $range, $sink, function (): void {
                $this->closeSession();
            });
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

    // ── internals ────────────────────────────────────────────────────────────

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
    private function refuse(ResponseSink $sink, int $status, array $headers = []): void
    {
        if ($sink->opened()) {
            return;
        }
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
