<?php

namespace Kanboard\Plugin\Matinee\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\Matinee\Crypto\CryptoException;
use Kanboard\Plugin\Matinee\Crypto\KeyStoreException;
use Kanboard\Plugin\Matinee\Crypto\RefSigner;
use Kanboard\Plugin\Matinee\Http\ByteRange;
use Kanboard\Plugin\Matinee\Http\PlexClient;
use Kanboard\Plugin\Matinee\Http\PlexException;
use Kanboard\Plugin\Matinee\Http\PlexPaths;
use Kanboard\Plugin\Matinee\Http\PlexStreamSink;
use Kanboard\Plugin\Matinee\Http\UnsafeUrlException;

/**
 * Playback (plan P3; spec §6, §7; C1, C3f, C3g, C4a, C5, C9). Turns a verified media ref into a player page or an audio
 * stream, re-authorizing on every use. Task 14 adds the hls half.
 *
 * - Every refusal is the one MatineeForbiddenException, decided before any Plex call where it can be: a bad, expired,
 *   other-user or wrong-kind ref, a server this user may not browse now (live rows), a part-path ref on the page. The
 *   page also needs Plex: the ref's section must be live and the item must sit in it, as a track.
 * - Every Plex client is named for the viewer (C9).
 * - Before a stream's sink opens, Plex, URL-guard, token and key-store failures are one MatineeConnectException, logged
 *   by stage with a fixed reason. After it opens, any failure is logged as STREAM_ENDED_LOG and nothing more is
 *   appended (C3g): the response has already started.
 * - An mp3 transcode holds a long-stream row (C4a) while it runs: claimed under the caps, heartbeat every
 *   HEARTBEAT_EVERY seconds of streaming (best-effort; a false beat ends the stream), stopped in a `finally`, and
 *   stopped by a shutdown function too in case the request dies first (C3f).
 */
final class PlaybackModel extends Base
{
    /** C1a: container => the audio codecs a browser decodes directly from it. */
    public const DIRECT_AUDIO = ['mp3' => ['mp3'], 'flac' => ['flac'], 'mp4' => ['aac'], 'm4a' => ['aac']];
    public const HEARTBEAT_EVERY = 30;
    public const REF_TTL = RefSigner::TTLS['media'];
    public const FAILED_LOG = 'Matinee: playback failed at %s (server %d, %s)';
    public const STREAM_ENDED_LOG = 'Matinee: stream ended early (server %d, %s)';
    public const HEARTBEAT_FAILED_LOG = 'Matinee: heartbeat failed (server %d)';
    public const STOP_FAILED_LOG = 'Matinee: transcode stop failed (server %d, %s)';
    /** C4d: the most stale rows one sweep handles. */
    public const SWEEP_LIMIT = 2;

    /** The part extensions each container family may carry (C1a: the part's extension matches its container). */
    private const PART_EXTENSIONS = ['mp3' => ['mp3'], 'flac' => ['flac'], 'mp4' => ['mp4', 'm4a'], 'm4a' => ['mp4', 'm4a']];
    private const SUBTITLE_GLUE = ' · ';

    /**
     * The player page for a media ref naming an item. A direct-eligible track (C1a) gets a media ref to its part;
     * anything else a fresh media ref to the item, which plays as a transcode.
     *
     * @return array{kind: 'audio', server: array{id: int, name: string}, title: string, subtitle: string, mediaRef: string}
     */
    public function page(int $userId, string $ref): array
    {
        $p = $this->signer()->verify($ref, $userId, 'media');
        if (! PlexPaths::isItem($p['r'])) {
            throw new MatineeForbiddenException();
        }
        $server = $this->authorize($userId, $p['sv']);
        $client = $this->client($server, $userId);

        $live = $this->plex($server, 'sections', fn () => $client->sections());
        if (! $this->policy()->sectionAllowed($live, $p['s'])) {
            throw new MatineeForbiddenException();
        }
        $item = $this->plex($server, 'item', fn () => $client->item($p['r']));
        if ($item['section'] === null || $item['section'] !== $p['s'] || $item['type'] !== 'track') {
            throw new MatineeForbiddenException();
        }

        $resource = self::directPart($item) ?? $p['r'];
        return [
            'kind' => 'audio',
            'server' => ['id' => (int) $server['id'], 'name' => (string) $server['name']],
            'title' => $item['title'],
            'subtitle' => implode(self::SUBTITLE_GLUE, array_filter([$item['grandparentTitle'], $item['parentTitle']], static fn (string $t): bool => $t !== '')),
            'mediaRef' => $this->signer()->sign($userId, $p['sv'], $p['s'], 'media', $resource, self::REF_TTL),
        ];
    }

    /**
     * Streams what a media ref names into $sink: one clamped window of a direct part, or an mp3 transcode of an item.
     * $onAuthorized runs once, after the ref and the server check and before any claim or Plex call (C3d).
     *
     * @throws MatineeForbiddenException refused
     * @throws MatineeBusyException a stream cap is reached (transcode only)
     * @throws MatineeRangeException Plex answered 416 for the window
     * @throws MatineeConnectException a failure before $sink opened
     */
    public function media(int $userId, string $ref, ?string $range, PlexStreamSink $sink, \Closure $onAuthorized): void
    {
        $p = $this->signer()->verify($ref, $userId, 'media');
        $server = $this->authorize($userId, $p['sv']);
        $onAuthorized();
        $serverId = (int) $server['id'];
        $client = $this->client($server, $userId);

        if (PlexPaths::isPart($p['r'])) {
            [$start, $end] = ByteRange::window($range);
            $tracked = self::tracked($sink);
            $result = $this->stream($serverId, 'part', $tracked, fn () => $client->part($p['r'], $start, $end, $tracked));
            if ($result !== null && $result['status'] === 416) {
                throw new MatineeRangeException((int) $result['total']);
            }
            return;
        }

        $transcodes = $this->transcodes();
        $now = $this->now();
        ['id' => $rowId, 'session' => $session] = $transcodes->claim($userId, $serverId, 'mp3', $now);
        try {
            ($this->container['matineeShutdown'])(static function () use ($transcodes, $rowId): void {
                $transcodes->stop($rowId);
            });
            $lastBeat = $now;
            $beat = function () use ($transcodes, $rowId, $serverId, &$lastBeat): bool {
                $now = $this->now();
                if ($now - $lastBeat < self::HEARTBEAT_EVERY) {
                    return true;
                }
                $lastBeat = $now;
                try {
                    return $transcodes->heartbeat($rowId, $now);
                } catch (\Throwable) {
                    $this->logger->warning(sprintf(self::HEARTBEAT_FAILED_LOG, $serverId));
                    return true;
                }
            };
            $tracked = self::tracked($sink, $beat);
            $this->stream($serverId, 'transcode', $tracked, fn () => $client->musicTranscode($p['r'], $session, $tracked));
        } finally {
            $transcodes->stop($rowId);
        }
    }

    /**
     * Stops this user's long stream (C5d). An unknown session, or someone else's, is Forbidden; an already stopped one
     * of their own is a no-op. Stopping only frees resources, so no browse right is needed.
     */
    public function stop(int $userId, string $session): void
    {
        $row = $this->transcodes()->find($session, $userId) ?? throw new MatineeForbiddenException();
        $this->halt($row);
    }

    /**
     * Keeps this user's HLS session alive (C2g). The row must be theirs, hls and not stopped; on a server they may no
     * longer browse it is stopped and Forbidden, and a heartbeat that finds it stale is Forbidden too (never revived).
     */
    public function ping(int $userId, string $session): void
    {
        $row = $this->transcodes()->find($session, $userId);
        if ($row === null || $row['kind'] !== 'hls' || (int) $row['stopped'] !== 0) {
            throw new MatineeForbiddenException();
        }
        $server = $this->servers()->get((int) $row['server_id']);
        if ($server === null || ! $this->policy()->canBrowse($userId, $server)) {
            $this->halt($row);
            throw new MatineeForbiddenException();
        }
        if (! $this->transcodes()->heartbeat((int) $row['id'], $this->now())) {
            throw new MatineeForbiddenException();
        }
    }

    /** C4d: stops at most SWEEP_LIMIT stale rows, oldest first, and returns how many this call stopped. */
    public function sweep(): int
    {
        $stopped = 0;
        foreach ($this->transcodes()->stale($this->now(), self::SWEEP_LIMIT) as $row) {
            $stopped += $this->halt($row) ? 1 : 0;
        }
        return $stopped;
    }

    /** The lazy sweep's dice (C4d): one request in ten, by default. */
    public function maybeSweep(): void
    {
        if (($this->container['matineeSweepDice'])()) {
            $this->sweep();
        }
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * Marks the row stopped first (only one caller wins), then, for an hls row only, asks Plex to stop the transcode
     * (3 s) through a client named for the row's viewer. A disabled server or an inactive owner or viewer gets no Plex
     * call; any other failure is logged with a fixed reason and swallowed. True when this call stopped the row.
     */
    private function halt(array $row): bool
    {
        if (! $this->transcodes()->stop((int) $row['id'])) {
            return false;
        }
        if ($row['kind'] !== 'hls') {
            return true;
        }
        $serverId = (int) $row['server_id'];
        try {
            $server = $this->servers()->get($serverId) ?? throw new MatineeForbiddenException();
            $this->servers()->clientFor($server, (int) $row['user_id'])->hlsStop($row['session']);
        } catch (MatineeForbiddenException) {
            // Disabled, gone, or nobody active to name: the row is stopped and Plex times the transcode out itself.
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(self::STOP_FAILED_LOG, $serverId, self::reason($e)));
        }
        return true;
    }

    /** The part path when a track plays directly (C1a: no video codec at all, so null), else null. */
    private static function directPart(array $item): ?string
    {
        $codecs = self::DIRECT_AUDIO[$item['container']] ?? null;
        if ($codecs === null || ! in_array($item['audioCodec'], $codecs, true) || $item['videoCodec'] !== null || $item['part'] === null) {
            return null;
        }
        $ext = substr($item['part'], strrpos($item['part'], '.') + 1);
        return in_array($ext, self::PART_EXTENSIONS[$item['container']], true) ? $item['part'] : null;
    }

    /**
     * The sink handed to the client: it remembers whether the response opened, and runs $beat before each write. A
     * false beat (the row was stopped or went stale) or a false inner write ends the transfer.
     */
    private static function tracked(PlexStreamSink $inner, ?\Closure $beat = null): PlexStreamSink
    {
        return new class ($inner, $beat) implements PlexStreamSink {
            public bool $opened = false;

            public function __construct(private readonly PlexStreamSink $inner, private readonly ?\Closure $beat)
            {
            }

            public function open(int $status, array $headers): bool
            {
                $this->opened = true;
                return $this->inner->open($status, $headers);
            }

            public function write(string $chunk): bool
            {
                if ($this->beat !== null && ! ($this->beat)()) {
                    return false;
                }
                return $this->inner->write($chunk);
            }
        };
    }

    /**
     * Runs a streaming Plex call. Before $sink opened, a failure maps as in plex(); after, any failure is logged as
     * STREAM_ENDED_LOG and swallowed, since the response has already started (C3g).
     */
    private function stream(int $serverId, string $stage, PlexStreamSink $sink, callable $call): mixed
    {
        try {
            return $call();
        } catch (\Throwable $e) {
            if ($sink->opened) {
                $this->logger->warning(sprintf(self::STREAM_ENDED_LOG, $serverId, $e instanceof PlexException ? $e->reason : 'error'));
                return null;
            }
            throw $this->failure($serverId, $stage, $e);
        }
    }

    /** Runs a buffered Plex call; a failure maps as failure() says. */
    private function plex(array $server, string $stage, callable $call): mixed
    {
        try {
            return $call();
        } catch (\Throwable $e) {
            throw $this->failure((int) $server['id'], $stage, $e);
        }
    }

    /**
     * What a Plex call's failure becomes. Plex and URL-guard failures are the one connect failure, logged by stage with
     * a fixed reason; Forbidden and Busy pass through; an InvalidArgumentException (a path the client refused, which
     * the ref checks already rule out) reads as Forbidden, never a 500; anything else passes through.
     */
    private function failure(int $serverId, string $stage, \Throwable $e): \Throwable
    {
        if ($e instanceof \InvalidArgumentException) {
            return new MatineeForbiddenException();
        }
        if ($e instanceof PlexException || $e instanceof UnsafeUrlException) {
            $this->logger->info(sprintf(self::FAILED_LOG, $stage, $serverId, $e->reason));
            return new MatineeConnectException();
        }
        return $e;
    }

    /** The client named for the viewer (C9). A build failure (token, key store, stored URL) is a connect failure. */
    private function client(array $server, int $userId): PlexClient
    {
        try {
            return $this->servers()->clientFor($server, $userId);
        } catch (MatineeForbiddenException $e) {
            throw $e;
        } catch (PlexException | UnsafeUrlException | CryptoException | \InvalidArgumentException $e) {
            $this->logger->info(sprintf(self::FAILED_LOG, 'client', (int) $server['id'], self::reason($e)));
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
            $e instanceof CryptoException => 'token unopenable',
            default => 'error',
        };
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

    /** The ref signer; a key-store or ref-key failure is configuration: the one connect failure. */
    private function signer(): RefSigner
    {
        try {
            return $this->container['matineeRefSigner'];
        } catch (CryptoException $e) {
            $this->logger->info(sprintf(self::FAILED_LOG, 'refs', 0, self::reason($e)));
            throw new MatineeConnectException();
        }
    }

    private function now(): int
    {
        return ($this->container['matineeClock'])();
    }

    private function transcodes(): TranscodeModel
    {
        return $this->container['matineeTranscodeModel'];
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
