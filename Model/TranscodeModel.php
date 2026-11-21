<?php

namespace Kanboard\Plugin\Matinee\Model;

use Kanboard\Core\Base;
use Pimple\Container;

/**
 * Long streams (P3, C4): one matinee_transcodes row per HLS session, or per mp3 transcode while its request runs.
 * DB only: no Plex calls here.
 *
 * - A row is active while stopped = 0 and last_seen_at >= now - ACTIVE_WINDOW. A stale row never blocks a claim.
 * - claim() checks the per-server, per-user and global caps (Settings) and inserts in one transaction. Its first
 *   statement is the UPDATE of the `streams` lock row, which serializes every claim: on MariaDB the InnoDB snapshot
 *   is then taken after the lock, so the counts see every claim committed before this one.
 * - stop() is idempotent: only the call that flips the row returns true.
 */
final class TranscodeModel extends Base
{
    public const TABLE = 'matinee_transcodes';
    public const LOCKS_TABLE = 'matinee_locks';
    public const LOCK_NAME = 'streams';
    public const ACTIVE_WINDOW = 120;
    public const KINDS = ['hls', 'mp3'];
    public const RETENTION = 86400;
    public const SESSION_RE = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
    public const COLUMNS = ['id', 'server_id', 'user_id', 'session', 'kind', 'last_seen_at', 'stopped'];

    /** @var \Closure(): string mints the session uuid; tests pass a fixed one. */
    private \Closure $uuid;

    public function __construct(Container $container, ?\Closure $uuid = null)
    {
        parent::__construct($container);
        $this->uuid = $uuid ?? static fn (): string => self::uuidV4();
    }

    /**
     * Claims a long-stream slot for $userId on $serverId and returns its new session uuid.
     *
     * @throws MatineeBusyException a cap is reached, or the lock row is missing or could not be taken in time
     * @throws \InvalidArgumentException an unknown kind
     */
    public function claim(int $userId, int $serverId, string $kind, int $now): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown Matinee stream kind.');
        }
        $settings = new Settings($this->container);
        $caps = [$settings->streamCapServer(), $settings->streamCapUser(), $settings->streamCapGlobal()];

        $this->db->startTransaction();
        try {
            try {
                $locked = $this->db->execute(
                    'UPDATE '.self::LOCKS_TABLE.' SET n = n + 1 WHERE name = ?',
                    [self::LOCK_NAME]
                );
            } catch (\PicoDb\SQLException) {
                // A lock wait timeout (or any failure to take the lock) reads as busy.
                throw new MatineeBusyException();
            }
            if ($locked === false || $locked->rowCount() !== 1) {
                throw new MatineeBusyException();
            }

            $this->db->table(self::TABLE)->eq('stopped', 1)->lt('last_seen_at', $now - self::RETENTION)->remove();

            $since = $now - self::ACTIVE_WINDOW;
            if ($this->active($since)->eq('server_id', $serverId)->count() >= $caps[0]
                || $this->active($since)->eq('user_id', $userId)->count() >= $caps[1]
                || $this->active($since)->count() >= $caps[2]) {
                throw new MatineeBusyException();
            }

            $session = ($this->uuid)();
            $ok = $this->db->table(self::TABLE)->insert([
                'server_id' => $serverId,
                'user_id' => $userId,
                'session' => $session,
                'kind' => $kind,
                'created_at' => $now,
                'last_seen_at' => $now,
                'stopped' => 0,
            ]);
            if (! $ok) {
                throw new \RuntimeException('Matinee: could not record the stream.');
            }
            $this->db->closeTransaction();
            return $session;
        } catch (\Throwable $e) {
            $this->db->cancelTransaction();
            throw $e;
        }
    }

    /** The row for $session when it is well-formed and belongs to $userId; null otherwise. */
    public function find(string $session, int $userId): ?array
    {
        if (preg_match(self::SESSION_RE, $session) !== 1) {
            return null;
        }
        $row = $this->db->table(self::TABLE)
            ->columns(...self::COLUMNS)
            ->eq('session', $session)
            ->eq('user_id', $userId)
            ->findOne();
        return $row ?: null;
    }

    public function heartbeat(int $id, int $now): void
    {
        $this->db->table(self::TABLE)->eq('id', $id)->update(['last_seen_at' => $now]);
    }

    /** Marks the row stopped; true only for the call that changed it. */
    public function stop(int $id): bool
    {
        $done = $this->db->execute('UPDATE '.self::TABLE.' SET stopped = 1 WHERE id = ? AND stopped = 0', [$id]);
        return $done !== false && $done->rowCount() === 1;
    }

    /**
     * Rows not stopped whose last_seen_at is older than the active window, oldest first, at most $limit.
     *
     * @return list<array>
     */
    public function stale(int $now, int $limit = 2): array
    {
        return $this->db->table(self::TABLE)
            ->columns(...self::COLUMNS)
            ->eq('stopped', 0)
            ->lt('last_seen_at', $now - self::ACTIVE_WINDOW)
            ->asc('last_seen_at')
            ->asc('id')
            ->limit($limit)
            ->findAll();
    }

    private function active(int $since): \PicoDb\Table
    {
        return $this->db->table(self::TABLE)->eq('stopped', 0)->gte('last_seen_at', $since);
    }

    /** A lowercase RFC 4122 version 4 uuid. */
    private static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
