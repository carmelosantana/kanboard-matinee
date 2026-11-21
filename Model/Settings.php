<?php

namespace Kanboard\Plugin\Matinee\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\Matinee\Http\UrlGuard;

/**
 * Matinee's config keys (spec §3, §4, §11 S1). The methods here read with ConfigModel::getOption, one query per call,
 * so a change an admin saves applies at once, even later in the same request. UrlGuard::fromConfig reads the allowed
 * hosts and private targets with ConfigModel::get instead, which goes through the per-request memoryCache of the whole
 * table: within one request it sees the values as first loaded.
 */
final class Settings extends Base
{
    /** Who may connect a server: everyone (any active user) or admins (D3). */
    public const CONNECT_POLICY = 'matinee_connect_policy';
    public const POLICY_EVERYONE = 'everyone';
    public const POLICY_ADMINS = 'admins';

    public const ALLOWED_HOSTS = UrlGuard::ALLOWED_HOSTS_KEY;
    /** S1 replaced the blanket matinee_allow_private with explicit targets. */
    public const PRIVATE_TARGETS = UrlGuard::PRIVATE_TARGETS_KEY;
    /** The per-install X-Plex-Client-Identifier. */
    public const CLIENT_ID = 'matinee_client_id';

    /** S1: connect and verify attempts per user per window, and servers per owner; the admin may change both. */
    public const ATTEMPT_LIMIT_KEY = 'matinee_attempt_limit';
    public const MAX_SERVERS_KEY = 'matinee_max_servers';
    public const ATTEMPT_LIMIT = 10;
    public const ATTEMPT_WINDOW = 600;
    public const MAX_SERVERS = 3;

    /** P3 C4b: active long streams (HLS sessions, mp3 transcodes) per server, per user and on the whole install. */
    public const STREAM_CAP_SERVER_KEY = 'matinee_stream_cap_server';
    public const STREAM_CAP_USER_KEY = 'matinee_stream_cap_user';
    public const STREAM_CAP_GLOBAL_KEY = 'matinee_stream_cap_global';
    public const STREAM_CAP_SERVER = 4;
    public const STREAM_CAP_USER = 2;
    public const STREAM_CAP_GLOBAL = 5;

    public function connectPolicy(): string
    {
        return $this->configModel->getOption(self::CONNECT_POLICY) === self::POLICY_ADMINS ? self::POLICY_ADMINS : self::POLICY_EVERYONE;
    }

    public function attemptLimit(): int
    {
        return $this->positiveInt(self::ATTEMPT_LIMIT_KEY, self::ATTEMPT_LIMIT);
    }

    public function maxServers(): int
    {
        return $this->positiveInt(self::MAX_SERVERS_KEY, self::MAX_SERVERS);
    }

    public function streamCapServer(): int
    {
        return $this->positiveInt(self::STREAM_CAP_SERVER_KEY, self::STREAM_CAP_SERVER);
    }

    public function streamCapUser(): int
    {
        return $this->positiveInt(self::STREAM_CAP_USER_KEY, self::STREAM_CAP_USER);
    }

    public function streamCapGlobal(): int
    {
        return $this->positiveInt(self::STREAM_CAP_GLOBAL_KEY, self::STREAM_CAP_GLOBAL);
    }

    /** The per-install client id, or '' before ensureClientId() has run. Read-only: never writes. */
    public function clientId(): string
    {
        return (string) $this->configModel->getOption(self::CLIENT_ID);
    }

    /**
     * The client id, created (a uuid v4) when missing. Creating it saves config, which opens and commits a
     * transaction: call outside one. ServerModel calls it before building any client.
     */
    public function ensureClientId(): string
    {
        $id = $this->clientId();
        if ($id !== '') {
            return $id;
        }
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $this->configModel->save([self::CLIENT_ID => vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4))]);
        // Re-read, so a concurrent first use settles on what was stored.
        return $this->clientId();
    }

    private function positiveInt(string $key, int $default): int
    {
        $raw = (string) $this->configModel->getOption($key);
        return ctype_digit($raw) && (int) $raw >= 1 ? (int) $raw : $default;
    }
}
