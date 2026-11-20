<?php

namespace Kanboard\Plugin\Matinee\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Matinee\Crypto\CryptoException;
use Kanboard\Plugin\Matinee\Crypto\KeyStore;
use Kanboard\Plugin\Matinee\Crypto\Subkeys;
use Kanboard\Plugin\Matinee\Crypto\TokenBox;
use Kanboard\Plugin\Matinee\Http\PlexClient;
use Kanboard\Plugin\Matinee\Http\PlexException;
use Kanboard\Plugin\Matinee\Http\UnsafeUrlException;
use Kanboard\Plugin\Matinee\Http\UrlGuard;

/**
 * Plex servers (spec §4, §2; §11 S1, S6, S7, S12). Every read of a server row goes through here, and tokenFor() is
 * the only place a token is decrypted.
 *
 * - get(), listForOwner() and listAll() select COLUMNS only: never token_nonce, token_ct or key_id.
 * - Connect, verify and update fail with one MatineeConnectException('matinee.connect_failed'), whatever the stage;
 *   the stage and the host (never the URL or token) go to the log at info level.
 * - Connect and verify (and an update, which probes the server too) each count one attempt toward the actor's limit
 *   of Settings::attemptLimit() per Settings::ATTEMPT_WINDOW seconds; rows over an hour old are pruned on insert.
 * - The token is sealed with AD `serverId|ownerId|baseUrl|machineId`, so the row is inserted first (for its id),
 *   then sealed and updated, in one transaction.
 * - A disabled server, or one whose owner is inactive (users.is_active read on every call), hands out no token.
 */
final class ServerModel extends Base
{
    public const TABLE = 'matinee_servers';
    public const ATTEMPTS_TABLE = 'matinee_attempts';

    /** The columns any caller may see. */
    public const COLUMNS = ['id', 'owner_user_id', 'name', 'base_url', 'machine_id', 'is_enabled', 'status', 'last_checked_at', 'created_at', 'updated_at'];

    public const STATUS_OK = 'ok';
    public const STATUS_UNREACHABLE = 'unreachable';
    public const STATUS_RECONNECT = 'reconnect';

    private const ATTEMPT_RETENTION = 3600;
    private const NAME_MAX = 100;
    private const TOKEN_MAX = 255;

    public function connect(int $userId, string $name, string $url, #[\SensitiveParameter] string $token): int
    {
        $actor = $this->activeUser($userId);
        if ($actor === null) {
            $this->fail('connect', 'actor');
        }
        if ($this->settings()->connectPolicy() === Settings::POLICY_ADMINS && $actor['role'] !== Role::APP_ADMIN) {
            $this->fail('connect', 'policy');
        }
        if (! $this->recordAttempt($userId)) {
            $this->fail('connect', 'rate_limit');
        }
        if ($this->db->table(self::TABLE)->eq('owner_user_id', $userId)->count() >= $this->settings()->maxServers()) {
            $this->fail('connect', 'server_limit');
        }
        $url = $this->normalise('connect', $url);
        $host = self::host($url);
        $name = $this->cleanName($name);
        if ($name === null || ! self::validToken($token)) {
            $this->fail('connect', 'input', $host);
        }
        if ($this->isDuplicate($userId, $url)) {
            $this->fail('connect', 'duplicate', $host);
        }

        $machineId = $this->probe('connect', $host, fn () => $this->makeClient($url, $token, null, $actor));

        $now = time();
        $this->db->startTransaction();
        try {
            $ok = $this->db->table(self::TABLE)->insert([
                'owner_user_id' => $userId,
                'name' => $name,
                'base_url' => $url,
                'machine_id' => $machineId,
                'token_nonce' => '',
                'token_ct' => '',
                'key_id' => '',
                'is_enabled' => 1,
                'status' => self::STATUS_OK,
                'last_checked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (! $ok) {
                throw new \RuntimeException('insert failed');
            }
            $id = (int) $this->db->getLastId();
            $this->writeToken($id, $userId, $url, $machineId, $token);
            $this->db->closeTransaction();
        } catch (\Throwable) {
            $this->db->cancelTransaction();
            $this->fail('connect', 'store', $host);
        }
        return $id;
    }

    /**
     * Owner only. A changed URL needs a token, and so does a changed machine id: the server is probed with the given
     * token, or the stored one, before anything is written.
     */
    public function update(int $serverId, int $actorId, string $name, string $url, #[\SensitiveParameter] ?string $token): void
    {
        $server = $this->get($serverId);
        $actor = $this->activeUser($actorId);
        if ($server === null || $actor === null || (int) $server['owner_user_id'] !== $actorId) {
            throw new MatineeForbiddenException();
        }
        $url = $this->normalise('update', $url);
        $host = self::host($url);
        $urlChanged = $url !== $server['base_url'];
        if ($urlChanged && $token === null) {
            $this->fail('update', 'token_required', $host, null, MatineeConnectException::TOKEN_REQUIRED);
        }
        $name = $this->cleanName($name);
        if ($name === null || ($token !== null && ! self::validToken($token))) {
            $this->fail('update', 'input', $host);
        }
        if ((int) $server['is_enabled'] !== 1) {
            $this->fail('update', 'disabled', $host);
        }
        if (! $this->recordAttempt($actorId)) {
            $this->fail('update', 'rate_limit', $host);
        }
        if ($urlChanged && $this->isDuplicate($actorId, $url, $serverId)) {
            $this->fail('update', 'duplicate', $host);
        }

        $machineId = $this->probe('update', $host, fn () => $this->makeClient($url, $token ?? $this->tokenFor($server), null, $actor));
        if ($token === null && $machineId !== $server['machine_id']) {
            $this->fail('update', 'machine_changed', $host, null, MatineeConnectException::TOKEN_REQUIRED);
        }

        $now = time();
        $fields = ['name' => $name, 'status' => self::STATUS_OK, 'last_checked_at' => $now, 'updated_at' => $now];
        try {
            if ($token !== null) {
                $this->writeToken($serverId, $actorId, $url, $machineId, $token, $fields + ['base_url' => $url, 'machine_id' => $machineId]);
            } elseif (! $this->db->table(self::TABLE)->eq('id', $serverId)->update($fields)) {
                throw new \RuntimeException('update failed');
            }
        } catch (\Throwable) {
            $this->fail('update', 'store', $host);
        }
    }

    /**
     * Owner or admin: re-runs identity() then sections() against the stored machine id and records the status
     * (ok, unreachable, or reconnect for a rejected token, a changed machine or an unopenable token).
     * Returns the status on success; any failure is the generic MatineeConnectException.
     */
    public function verify(int $serverId, int $actorId): string
    {
        $server = $this->get($serverId);
        if ($server === null || ! $this->ownerOrAdmin($server, $actorId)) {
            throw new MatineeForbiddenException();
        }
        $host = self::host($server['base_url']);
        if (! $this->recordAttempt($actorId)) {
            $this->fail('verify', 'rate_limit', $host);
        }
        $this->probe('verify', $host, fn () => $this->clientFor($server), function (\Throwable $e) use ($serverId) {
            $status = self::statusFor($e);
            if ($status !== null) {
                $this->setStatus($serverId, $status);
            }
        });
        $this->setStatus($serverId, self::STATUS_OK);
        return self::STATUS_OK;
    }

    /** Owner or admin. Shares and their sections cascade. */
    public function remove(int $serverId, int $actorId): void
    {
        $server = $this->get($serverId);
        if ($server === null || ! $this->ownerOrAdmin($server, $actorId)) {
            throw new MatineeForbiddenException();
        }
        $this->db->table(self::TABLE)->eq('id', $serverId)->remove();
    }

    /** Admin only: the kill switch. */
    public function setEnabled(int $serverId, bool $on, int $actorId): void
    {
        if (! $this->isActiveAdmin($actorId) || $this->get($serverId) === null) {
            throw new MatineeForbiddenException();
        }
        $this->db->table(self::TABLE)->eq('id', $serverId)->update(['is_enabled' => $on ? 1 : 0, 'updated_at' => time()]);
    }

    /** @return array<string, mixed>|null the public columns only */
    public function get(int $id): ?array
    {
        return $this->db->table(self::TABLE)->columns(...self::COLUMNS)->eq('id', $id)->findOne() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function listForOwner(int $userId): array
    {
        return $this->db->table(self::TABLE)->columns(...self::COLUMNS)->eq('owner_user_id', $userId)->asc('name')->asc('id')->findAll();
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        return $this->db->table(self::TABLE)->columns(...self::COLUMNS)->asc('id')->findAll();
    }

    /**
     * The single decrypt site. Reads the row by $server['id'] (nothing else from the caller's array is trusted) and
     * opens the token with the key the row names. A token that will not open marks the server `reconnect` and the
     * CryptoException propagates. A row sealed under a key that is no longer the active one is re-sealed.
     *
     * @throws MatineeForbiddenException for a missing or disabled server or an inactive owner
     * @throws CryptoException
     */
    public function tokenFor(array $server): string
    {
        $id = (int) ($server['id'] ?? 0);
        $row = $this->db->table(self::TABLE)
            ->columns('id', 'owner_user_id', 'base_url', 'machine_id', 'token_nonce', 'token_ct', 'key_id', 'is_enabled')
            ->eq('id', $id)
            ->findOne();
        if (! $row || (int) $row['is_enabled'] !== 1 || $this->activeUser((int) $row['owner_user_id']) === null) {
            throw new MatineeForbiddenException();
        }
        $ownerId = (int) $row['owner_user_id'];

        // A KeyStore failure (a malformed env key, an unsafe key dir) is configuration, not this row: no status change.
        $keys = $this->keyStore()->allKeys();
        try {
            $root = $keys[$row['key_id']] ?? throw new CryptoException('token: cannot open');
            $subkey = Subkeys::token($root);
            try {
                $token = (new TokenBox($subkey))->open($row['token_nonce'], $row['token_ct'], $id, $ownerId, $row['base_url'], $row['machine_id']);
            } finally {
                sodium_memzero($subkey);
            }
        } catch (CryptoException $e) {
            $this->setStatus($id, self::STATUS_RECONNECT);
            throw $e;
        }

        if ($row['key_id'] !== $this->keyStore()->activeKeyId()) {
            try {
                $this->writeToken($id, $ownerId, $row['base_url'], $row['machine_id'], $token, [], $row['key_id']);
            } catch (\Throwable) {
                $this->logger->info('Matinee: re-sealing server '.$id.' under the active key failed');
            }
        }
        return $token;
    }

    /** A client for the stored server, pinned to its machine id and named for its owner. */
    public function clientFor(array $server): PlexClient
    {
        $row = $this->get((int) ($server['id'] ?? 0)) ?? throw new MatineeForbiddenException();
        $token = $this->tokenFor($row);
        $owner = $this->activeUser((int) $row['owner_user_id']) ?? throw new MatineeForbiddenException();
        return $this->makeClient($row['base_url'], $token, $row['machine_id'], $owner);
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function settings(): Settings
    {
        return new Settings($this->container);
    }

    private function keyStore(): KeyStore
    {
        return $this->container['matineeKeyStore'];
    }

    private function makeClient(string $baseUrl, #[\SensitiveParameter] string $token, ?string $machineId, array $user): PlexClient
    {
        $factory = $this->container['matineePlexClientFactory'];
        return $factory($baseUrl, $token, $machineId, PlexClient::deviceName((string) $user['username'], (int) $user['id']));
    }

    /**
     * Builds the client, then identity() and sections(), in that order (only identity() requires the machine id).
     * Any failure, PlexException and UnsafeUrlException included, runs $onFailure and becomes the generic failure.
     */
    private function probe(string $op, string $host, callable $client, ?callable $onFailure = null): string
    {
        $stage = 'client';
        try {
            $c = $client();
            $stage = 'identity';
            $machineId = $c->identity()['machineIdentifier'];
            $stage = 'sections';
            $c->sections();
        } catch (\Throwable $e) {
            if ($onFailure !== null) {
                $onFailure($e);
            }
            $this->fail($op, $stage, $host, self::reason($e));
        }
        return $machineId;
    }

    /** Logs the stage with the host only, then throws the one generic failure. */
    private function fail(string $op, string $stage, string $host = '-', ?string $reason = null, string $code = MatineeConnectException::CONNECT_FAILED): never
    {
        $this->logger->info(sprintf('Matinee: %s failed at %s (host %s%s)', $op, $stage, $host, $reason === null ? '' : ', '.$reason));
        throw new MatineeConnectException($code);
    }

    /** A fixed reason code for the log: never an exception message, which may carry upstream text. */
    private static function reason(\Throwable $e): string
    {
        return match (true) {
            $e instanceof PlexException, $e instanceof UnsafeUrlException => $e->reason,
            $e instanceof CryptoException => 'token unopenable',
            $e instanceof MatineeForbiddenException => 'forbidden',
            default => 'error',
        };
    }

    private static function statusFor(\Throwable $e): ?string
    {
        if ($e instanceof MatineeForbiddenException) {
            return null;
        }
        if ($e instanceof CryptoException
            || ($e instanceof PlexException && in_array($e->reason, [PlexException::TOKEN_REJECTED, PlexException::SERVER_CHANGED], true))) {
            return self::STATUS_RECONNECT;
        }
        return self::STATUS_UNREACHABLE;
    }

    private function normalise(string $op, string $url): string
    {
        try {
            return UrlGuard::normaliseBaseUrl($url);
        } catch (UnsafeUrlException) {
            $this->fail($op, 'url');
        }
    }

    /** The host of a normalised base URL. */
    private static function host(string $url): string
    {
        return (string) parse_url($url, PHP_URL_HOST);
    }

    /** @return array{0: string, 1: int} host and effective port of a normalised base URL */
    private static function endpoint(string $url): array
    {
        $parts = (array) parse_url($url);
        $port = $parts['port'] ?? (strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80);
        return [strtolower((string) ($parts['host'] ?? '')), (int) $port];
    }

    /** Same host and effective port as another of this owner's servers (`http://h` is `http://h:80`). */
    private function isDuplicate(int $ownerId, string $url, ?int $exceptId = null): bool
    {
        $endpoint = self::endpoint($url);
        foreach ($this->db->table(self::TABLE)->columns('id', 'base_url')->eq('owner_user_id', $ownerId)->findAll() as $row) {
            if ((int) $row['id'] !== $exceptId && self::endpoint((string) $row['base_url']) === $endpoint) {
                return true;
            }
        }
        return false;
    }

    private function cleanName(string $name): ?string
    {
        $name = trim($name);
        return $name === '' || mb_strlen($name, 'UTF-8') > self::NAME_MAX || preg_match('/[\x00-\x1f\x7f]/', $name) === 1 ? null : $name;
    }

    private static function validToken(#[\SensitiveParameter] string $token): bool
    {
        return $token !== '' && strlen($token) <= self::TOKEN_MAX && preg_match('/[\x00-\x20\x7f]/', $token) !== 1;
    }

    /** Counts this user's attempts in the window; under the limit, records one (pruning old rows) and returns true. */
    private function recordAttempt(int $userId): bool
    {
        $now = time();
        $recent = $this->db->table(self::ATTEMPTS_TABLE)
            ->eq('user_id', $userId)
            ->gt('created_at', $now - Settings::ATTEMPT_WINDOW)
            ->count();
        if ($recent >= $this->settings()->attemptLimit()) {
            return false;
        }
        $this->db->table(self::ATTEMPTS_TABLE)->lt('created_at', $now - self::ATTEMPT_RETENTION)->remove();
        $this->db->table(self::ATTEMPTS_TABLE)->insert(['user_id' => $userId, 'created_at' => $now]);
        return true;
    }

    /**
     * Seals $token under the active key with the row's AD and writes it (with $fields) in one UPDATE.
     * With $expectedKeyId the update applies only if the row still holds that key id (a concurrent re-seal wins).
     */
    private function writeToken(int $id, int $ownerId, string $baseUrl, string $machineId, #[\SensitiveParameter] string $token, array $fields = [], ?string $expectedKeyId = null): void
    {
        $root = $this->keyStore()->rootKey();
        $subkey = Subkeys::token($root);
        try {
            $sealed = (new TokenBox($subkey))->seal($token, $id, $ownerId, $baseUrl, $machineId);
        } finally {
            sodium_memzero($subkey);
        }
        $query = $this->db->table(self::TABLE)->eq('id', $id);
        if ($expectedKeyId !== null) {
            $query->eq('key_id', $expectedKeyId);
        }
        $ok = $query->update($fields + [
            'token_nonce' => $sealed['nonce'],
            'token_ct' => $sealed['ct'],
            'key_id' => KeyStore::keyIdFor($root),
        ]);
        if (! $ok) {
            throw new \RuntimeException('token write failed');
        }
    }

    private function setStatus(int $id, string $status): void
    {
        $this->db->table(self::TABLE)->eq('id', $id)->update(['status' => $status, 'last_checked_at' => time()]);
    }

    /** @return array{id: int|string, username: string, role: string}|null the user, if they exist and are active */
    private function activeUser(int $id): ?array
    {
        $user = $this->db->table('users')->columns('id', 'username', 'role', 'is_active')->eq('id', $id)->findOne();
        return $user && (int) $user['is_active'] === 1 ? $user : null;
    }

    private function isActiveAdmin(int $id): bool
    {
        return ($this->activeUser($id)['role'] ?? null) === Role::APP_ADMIN;
    }

    private function ownerOrAdmin(array $server, int $actorId): bool
    {
        return ((int) $server['owner_user_id'] === $actorId && $this->activeUser($actorId) !== null) || $this->isActiveAdmin($actorId);
    }
}
