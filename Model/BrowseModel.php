<?php

namespace Kanboard\Plugin\Matinee\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\Matinee\Crypto\CryptoException;
use Kanboard\Plugin\Matinee\Crypto\KeyStoreException;
use Kanboard\Plugin\Matinee\Crypto\RefSigner;
use Kanboard\Plugin\Matinee\Http\PlexClient;
use Kanboard\Plugin\Matinee\Http\PlexException;
use Kanboard\Plugin\Matinee\Http\PlexPaths;
use Kanboard\Plugin\Matinee\Http\UnsafeUrlException;

/**
 * Browse (spec §6 as adapted to the light scope; rulings B2, B3, B4; §11 S13). Turns a verified ref into a listing or
 * an image, re-authorizing on every use, and mints the refs the next page needs.
 *
 * - Every refusal is the one MatineeForbiddenException: a bad, expired, other-user or wrong-kind ref, a missing
 *   server, `canBrowse` false (live rows), a section no longer live, a container from another section or none, a
 *   `start` outside 0..MAX_START, an art size off the allow-list. Refusals are decided before any client is built,
 *   except the live-section and container checks, which need Plex.
 * - Every Plex, token, key-store or client-build failure is one MatineeConnectException('matinee.connect_failed');
 *   the stage, the server id (when there is one) and a fixed reason code go to the log (never a message, URL or
 *   token). A key-store failure leaves the server's status alone.
 * - A listing re-fetches the live sections (B3); art does not (B2).
 * - Refs are minted for 3600 s and carry the server and section of the ref they came from.
 */
final class BrowseModel extends Base
{
    /** Ruling B4. */
    public const PAGE_SIZE = 60;
    public const MAX_START = 1000000;
    public const REF_TTL = RefSigner::TTLS['browse'];

    private const SUBTITLE_GLUE = ' · ';

    /** @return list<array{key: string, title: string, type: string, ref: string}> */
    public function sections(int $userId, int $serverId): array
    {
        $server = $this->authorize($userId, $serverId);
        $signer = $this->signer(); // before any Plex call
        $out = [];
        foreach ($this->live($server, 'sections') as $s) {
            $out[] = $s + ['ref' => $signer->sign($userId, $serverId, $s['key'], 'browse', PlexPaths::sectionAll($s['key']), self::REF_TTL)];
        }
        return $out;
    }

    /**
     * One page (PAGE_SIZE items from $start) of the listing a browse ref names.
     *
     * @return array{server: array{id: int, name: string}, section: string, sectionTitle: string, total: int,
     *   start: int, pageSize: int, items: list<array{title: string, subtitle: string, year: ?int, type: string, index: ?int, leafCount: ?int,
     *   browseRef: ?string, artRef: ?string}>}
     */
    public function listing(int $userId, string $ref, int $start): array
    {
        if ($start < 0 || $start > self::MAX_START) {
            throw new MatineeForbiddenException();
        }
        $p = $this->verify($ref, $userId, 'browse');
        $server = $this->authorize($userId, $p['sv']);

        $live = $this->live($server, 'sections');
        if (! $this->policy()->sectionAllowed($live, $p['s'])) {
            throw new MatineeForbiddenException();
        }
        // The breadcrumb's title comes from the same live fetch: no extra Plex request.
        $sectionTitle = '';
        foreach ($live as $s) {
            if ($s['key'] === $p['s']) {
                $sectionTitle = $s['title'];
                break;
            }
        }
        $page = $this->plex($server, 'children', fn (PlexClient $c) => $c->children($p['r'], $start, self::PAGE_SIZE));
        if ($page['section'] === null || $page['section'] !== $p['s']) {
            throw new MatineeForbiddenException();
        }

        $signer = $this->signer();
        $mint = fn (string $kind, ?string $path): ?string => $path === null || ! self::fits($kind, $path)
            ? null
            : $signer->sign($userId, $p['sv'], $p['s'], $kind, $path, self::REF_TTL);

        $items = [];
        foreach ($page['items'] as $i) {
            $items[] = [
                'title' => $i['title'],
                'subtitle' => implode(self::SUBTITLE_GLUE, array_filter([$i['grandparentTitle'], $i['parentTitle']], static fn (string $t): bool => $t !== '')),
                'year' => $i['year'],
                'type' => $i['type'],
                'index' => $i['index'],
                'leafCount' => $i['leafCount'],
                'browseRef' => $mint('browse', $i['key']),
                'artRef' => $mint('art', $i['thumb']),
            ];
        }

        return [
            'server' => ['id' => (int) $server['id'], 'name' => (string) $server['name']],
            'section' => $p['s'],
            'sectionTitle' => $sectionTitle,
            'total' => $page['total'],
            'start' => $start,
            'pageSize' => self::PAGE_SIZE,
            'items' => $items,
        ];
    }

    /**
     * The image an art ref names, at one of PlexClient::ART_SIZES. No live-sections check (ruling B2).
     *
     * @return array{type: string, body: string}
     */
    public function art(int $userId, string $ref, int $size): array
    {
        $p = $this->verify($ref, $userId, 'art');
        if (! in_array($size, PlexClient::ART_SIZES, true)) {
            throw new MatineeForbiddenException();
        }
        $server = $this->authorize($userId, $p['sv']);
        return $this->plex($server, 'art', fn (PlexClient $c) => $c->art($p['r'], $size));
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** @return array{sv: int, s: string, k: string, r: string} */
    private function verify(string $ref, int $userId, string $kind): array
    {
        $p = $this->signer()->verify($ref, $userId);
        if ($p['k'] !== $kind) {
            throw new MatineeForbiddenException();
        }
        return $p;
    }

    /** The server's public row when this user may browse it now (live rows); missing reads the same as refused. */
    private function authorize(int $userId, int $serverId): array
    {
        $server = $this->servers()->get($serverId);
        if ($server === null || ! $this->policy()->canBrowse($userId, $server)) {
            throw new MatineeForbiddenException();
        }
        return $server;
    }

    /** @return list<array{key: string, title: string, type: string}> */
    private function live(array $server, string $stage): array
    {
        return $this->plex($server, $stage, fn (PlexClient $c) => $c->sections());
    }

    /**
     * Builds the client, then runs $call. Plex, URL-guard, token and key-store failures become the one connect
     * failure, logged by stage; Forbidden passes through. An InvalidArgumentException while building the client (a
     * stored base URL PlexClient refuses) is a connect failure too; one from $call (a path or size the client
     * refused, which the ref checks should already rule out) reads as Forbidden, never as a 500.
     */
    private function plex(array $server, string $stage, callable $call): mixed
    {
        $at = 'client';
        try {
            $client = $this->servers()->clientFor($server);
            $at = $stage;
            return $call($client);
        } catch (MatineeForbiddenException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            if ($at !== 'client') {
                throw new MatineeForbiddenException();
            }
            $this->logger->info(sprintf('Matinee: browse failed at client (server %d, %s)', (int) $server['id'], self::reason($e)));
            throw new MatineeConnectException();
        } catch (PlexException | UnsafeUrlException | CryptoException $e) {
            $this->logger->info(sprintf('Matinee: browse failed at %s (server %d, %s)', $at, (int) $server['id'], self::reason($e)));
            throw new MatineeConnectException();
        }
    }

    /** A fixed reason code for the log: never an exception message. */
    private static function reason(\Throwable $e): string
    {
        return match (true) {
            $e instanceof PlexException, $e instanceof UnsafeUrlException => $e->reason,
            $e instanceof KeyStoreException => 'key store',
            $e instanceof \InvalidArgumentException => 'invalid',
            default => 'token unopenable',
        };
    }

    private static function fits(string $kind, string $path): bool
    {
        return $kind === 'browse' ? PlexPaths::isBrowse($path) : PlexPaths::isArt($path);
    }

    /**
     * The ref signer, built lazily from the key store. A key-store or ref-key failure (a malformed MATINEE_KEY, an
     * unsafe or unreadable key dir) is configuration: the one connect failure, logged at the refs stage.
     */
    private function signer(): RefSigner
    {
        try {
            return $this->container['matineeRefSigner'];
        } catch (CryptoException $e) {
            $this->logger->info(sprintf('Matinee: browse failed at refs (%s)', self::reason($e)));
            throw new MatineeConnectException();
        }
    }

    private function policy(): MatineePolicy
    {
        return $this->container['matineePolicy'];
    }

    private function servers(): ServerModel
    {
        return $this->container['matineeServerModel'];
    }
}
