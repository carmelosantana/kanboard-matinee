<?php

namespace Kanboard\Plugin\Matinee\Controller;

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Matinee\Http\PlexClient;
use Kanboard\Plugin\Matinee\Model\BrowseModel;
use Kanboard\Plugin\Matinee\Model\MatineeBusyException;
use Kanboard\Plugin\Matinee\Model\MatineeConnectException;
use Kanboard\Plugin\Matinee\Model\MatineeForbiddenException;

/**
 * Dashboard → Media (plan P2 Task 4; rulings B1, B4, B5; spec §6, §11 S9, S11). Any signed-in user reaches it (ACL
 * APP_USER); BrowseModel decides every page from the live rows and a signed ref, never from an id in the URL alone.
 *
 * - Pages: a refusal is core's 403 page; a Plex failure, or a key store that will not load, is one fixed sentence
 *   with a 502. Nothing a model or Plex said is shown.
 * - Art: the upstream bytes with exactly the S11 headers, or an empty 403 (refused), 502 (failed) or 503 with
 *   `Retry-After: 5` (the in-flight gate is full, M1). Never HTML.
 * - Links and image sources are Matinee URLs carrying a ref; a Plex path, host or token never reaches a page.
 */
class MatineeBrowseController extends MatineeBaseController
{
    /** The longest `start` accepted before the model's own range check (BrowseModel::MAX_START has 7 digits). */
    private const START_DIGITS = 7;

    /** Ruling B1, as the strings a URL carries. */
    private const ART_SIZES = ['144' => 144, '512' => 512];

    /** The browsable servers: the user's own and every shared one (ServerModel::listBrowsable). */
    public function index(): void
    {
        $rows = [];
        foreach ($this->servers()->listBrowsable($this->actorId()) as $server) {
            $rows[] = ['id' => (int) $server['id'], 'name' => (string) $server['name']];
        }
        $this->renderMedia('Matinee:browse/index', ['servers' => $rows]);
    }

    /** One server's live libraries, each linking to its listing by a browse ref. */
    public function server(): void
    {
        $serverId = $this->request->getIntegerParam('server_id');
        $sections = $this->attempt(fn () => $this->browse()->sections($this->actorId(), $serverId));
        if ($sections === null) {
            return;
        }
        $this->renderMedia('Matinee:browse/server', [
            'server' => ['id' => $serverId, 'name' => (string) ($this->servers()->get($serverId)['name'] ?? '')],
            'sections' => $sections,
        ]);
    }

    /** One page of the listing a browse ref names, from `start` (ruling B4). */
    public function listing(): void
    {
        $start = $this->param('start');
        if ($start === '') {
            $start = '0';
        }
        if (! ctype_digit($start) || strlen($start) > self::START_DIGITS) {
            throw new AccessForbiddenException();
        }
        $ref = $this->param('ref');
        $page = $this->attempt(fn () => $this->browse()->listing($this->actorId(), $ref, (int) $start));
        if ($page === null) {
            return;
        }
        $last = $page['start'] + $page['pageSize'];
        // A start past the end (a stale link to a library that shrank) is not refused: the page says there is
        // nothing here and links back to the first page, with no count and no Previous.
        $pastEnd = $page['total'] > 0 && $page['start'] >= $page['total'];
        $this->renderMedia('Matinee:browse/listing', [
            'page' => $page,
            'ref' => $ref,
            'pastEnd' => $pastEnd,
            'prev' => ! $pastEnd && $page['start'] > 0 ? max(0, $page['start'] - $page['pageSize']) : null,
            'next' => ! $pastEnd && $last < $page['total'] ? $last : null,
        ]);
    }

    /** One poster: the upstream bytes with the S11 headers, or an empty 403 or 502. */
    public function art(): void
    {
        $size = self::ART_SIZES[$this->param('size')] ?? null;
        if ($size === null) {
            $this->refuse(403);
            return;
        }
        try {
            $image = $this->browse()->art($this->actorId(), $this->param('ref'), $size);
        } catch (MatineeForbiddenException) {
            $this->refuse(403);
            return;
        } catch (MatineeConnectException) {
            $this->refuse(502);
            return;
        } catch (MatineeBusyException $e) {
            $this->refuse(503, ['Retry-After' => (string) $e->retryAfter]);
            return;
        }
        // The type goes into Content-Type as is, so it is checked here too, against the one allow-list.
        if (! in_array($image['type'], PlexClient::ART_TYPES, true)) {
            $this->refuse(502);
            return;
        }

        // Not binary(): it forces application/octet-stream and no-cache, which S11 replaces.
        $this->response->withStatusCode(200);
        $this->response->withContentType($image['type']);
        $this->response->withHeader('Cache-Control', 'private, max-age=3600');
        $this->response->withHeader('X-Content-Type-Options', 'nosniff');
        $this->response->withHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $this->response->withHeader('Content-Disposition', 'inline');
        $this->response->withHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $this->response->withBody($image['body']);
        // The session cache limiter already queued Expires and Pragma (no-cache); they contradict the S11
        // Cache-Control, so drop them. Skipped once output has started (under CLI, nothing was queued anyway).
        if (! headers_sent()) {
            header_remove('Pragma');
            header_remove('Expires');
        }
        $this->response->send();
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * Runs a model call for a page. Forbidden is core's 403; a connect failure (a Plex failure, or a key store that
     * will not load, which BrowseModel maps to the same) renders the fixed failure page and returns null; a full
     * in-flight gate (M1) renders the busy page with a 503 and its Retry-After and returns null.
     */
    private function attempt(callable $call): ?array
    {
        try {
            return $call();
        } catch (MatineeForbiddenException) {
            throw new AccessForbiddenException();
        } catch (MatineeConnectException) {
            $this->renderMedia('Matinee:browse/failed', [], 502);
            return null;
        } catch (MatineeBusyException $e) {
            $this->response->withHeader('Retry-After', (string) $e->retryAfter);
            $this->renderMedia('Matinee:play/busy', [], 503);
            return null;
        }
    }

    /** An empty art refusal: no body, so nothing for a browser to render or sniff. */
    private function refuse(int $status, array $headers = []): void
    {
        foreach ($headers as $name => $value) {
            $this->response->withHeader($name, $value);
        }
        $this->response->withHeader('X-Content-Type-Options', 'nosniff');
        $this->response->withHeader('Cache-Control', 'no-store');
        $this->response->status($status);
    }


    /** @return BrowseModel */
    private function browse(): object
    {
        return $this->container['matineeBrowseModel'];
    }
}
