<?php

namespace Kanboard\Plugin\Matinee\Model;

use Kanboard\Core\Security\Role;
use Pimple\Container;

/**
 * The one place Matinee decides an ability (spec "Light scope", §11 S6). Pure apart from the injected lookups, which
 * read the DB on every call: nothing is cached across calls, so a deactivated user, a lost admin role, a disabled or
 * unshared server counts on the next request.
 *
 * Every method that takes a server reads only its id from the caller's array and judges the live row.
 *
 * - Browse, rooms and playlists: an active user, on an enabled server whose owner is active, who is the owner or
 *   finds the server shared. An app admin is no one special here.
 * - Manage (verify, remove; disable is ServerModel's admin check): the active owner, or an active app admin.
 * - Toggle sharing: the active owner of an enabled server. Never an admin on someone else's server.
 */
final class MatineePolicy
{
    /**
     * @param \Closure(int): ?array $user          users row (role, is_active) or null
     * @param \Closure(int): ?array $server        matinee_servers public row (owner_user_id, is_enabled, is_shared) or null
     * @param \Closure(): string $connectPolicy    Settings::POLICY_EVERYONE or Settings::POLICY_ADMINS
     */
    public function __construct(
        private readonly \Closure $user,
        private readonly \Closure $server,
        private readonly \Closure $connectPolicy,
    ) {
    }

    /** The lookups over Kanboard's DB. */
    public static function fromContainer(Container $c): self
    {
        return new self(
            static fn (int $id): ?array => $c['db']->table('users')->columns('id', 'role', 'is_active')->eq('id', $id)->findOne() ?: null,
            static fn (int $id): ?array => $c['matineeServerModel']->get($id),
            static fn (): string => (new Settings($c))->connectPolicy(),
        );
    }

    public function canConnectServer(int $userId): bool
    {
        $user = $this->activeUser($userId);
        return $user !== null && (($this->connectPolicy)() !== Settings::POLICY_ADMINS || $user['role'] === Role::APP_ADMIN);
    }

    public function canManageServer(int $userId, array $server): bool
    {
        $live = $this->live($server);
        return $live !== null && $this->activeUser($userId) !== null
            && ((int) $live['owner_user_id'] === $userId || $this->isActiveAdmin($userId));
    }

    /**
     * Share ($on) or make private: only the active owner. Sharing also needs the server enabled; making it private
     * only narrows access, so the owner may do it while an admin has the server disabled.
     */
    public function canToggleShared(int $userId, array $server, bool $on = true): bool
    {
        $live = $this->live($server);
        return $live !== null && ($on === false || (int) $live['is_enabled'] === 1)
            && (int) $live['owner_user_id'] === $userId && $this->activeUser($userId) !== null;
    }

    public function canBrowse(int $userId, array $server): bool
    {
        $live = $this->live($server);
        if ($live === null || (int) $live['is_enabled'] !== 1 || $this->activeUser($userId) === null) {
            return false;
        }
        $ownerId = (int) $live['owner_user_id'];
        return $this->activeUser($ownerId) !== null && ($ownerId === $userId || (int) $live['is_shared'] === 1);
    }

    /** Start or control a watch room: anyone who may browse the server. */
    public function canControlRoom(int $userId, array $server): bool
    {
        return $this->canBrowse($userId, $server);
    }

    public function canEditPlaylist(int $userId, array $server): bool
    {
        return $this->canBrowse($userId, $server);
    }

    /** An active app admin by the live users row (never the session's copy): the one admin check Matinee makes. */
    public function isActiveAdmin(int $userId): bool
    {
        return ($this->activeUser($userId)['role'] ?? null) === Role::APP_ADMIN;
    }

    /**
     * A section key is reachable when the server lists it now. PlexClient::sections() has already dropped malformed
     * keys and every type outside the allow-list (S13), so membership is the whole check.
     *
     * @param list<array{key: string, title: string, type: string}> $liveSections from PlexClient::sections()
     */
    public function sectionAllowed(array $liveSections, string $sectionKey): bool
    {
        foreach ($liveSections as $s) {
            if (($s['key'] ?? null) === $sectionKey) {
                return true;
            }
        }
        return false;
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** The live row for the caller's server id, or null. */
    private function live(array $server): ?array
    {
        $id = (int) ($server['id'] ?? 0);
        return $id > 0 ? ($this->server)($id) : null;
    }

    private function activeUser(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $user = ($this->user)($id);
        return $user && (int) $user['is_active'] === 1 ? $user : null;
    }
}
