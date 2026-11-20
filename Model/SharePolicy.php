<?php

namespace Kanboard\Plugin\Matinee\Model;

use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Matinee\Http\PlexClient;
use Pimple\Container;

/**
 * The one place Matinee decides an ability (spec §5; §11 S5, S6, S13, S15; decision map R8). Pure apart from the
 * injected lookups, which read the DB on every call: nothing is cached across calls, so a disabled user, a lost role
 * or an archived project counts on the next request.
 *
 * Every method takes ids and the loaded share or server row, never a project id from the URL (S5): a project share
 * is judged by the project its own row names.
 *
 * A share is live when the share is enabled, its server exists and is enabled, the server's owner is active (S6),
 * and its audience still stands: a project share needs an active project; an everyone-share needs its creator to
 * still be an active app admin (S6). Use of a live share:
 * - project share: the project role from getUserRole (direct, else group). Viewer browses; member and any custom
 *   role also control rooms and edit playlists; a custom role is never a manager (S6).
 * - everyone-share: any active user browses, controls rooms and edits playlists.
 * - the server owner always browses live shares of their own server, in the audience or not: they own the media. That
 *   is all the ownership gives: rooms and playlists on a project share need a current member+ role like anyone else's,
 *   and on an everyone-share the owner has them as any active user does.
 * - an app admin is no one special for use: no project role, no browse.
 * Unlinking only removes access, so it does not need a live share: the server owner, an app admin, or a project
 * manager of the share's active project may unlink. Editing scope is the owner's, on an enabled server, and re-checks
 * the creation rule (S6): manager of the active project, or an active app admin for an everyone-share. The share kill
 * switch (enable, disable) is an active app admin's alone.
 */
final class SharePolicy
{
    public const AUDIENCE_PROJECT = 'project';
    public const AUDIENCE_EVERYONE = 'everyone';
    public const SECTION_KEY = '/\A\d{1,20}\z/';

    private const USE_BROWSE = 1;
    private const USE_MEMBER = 2;

    /**
     * @param \Closure(int): ?array $user          users row (role, is_active) or null
     * @param \Closure(int, int): string $projectRole the role in a project (project id, user id), '' for none
     * @param \Closure(int): ?array $project       projects row (is_active) or null
     * @param \Closure(int): ?array $server        matinee_servers public row (owner_user_id, is_enabled) or null
     * @param \Closure(): string $connectPolicy    Settings::POLICY_EVERYONE or Settings::POLICY_ADMINS
     */
    public function __construct(
        private readonly \Closure $user,
        private readonly \Closure $projectRole,
        private readonly \Closure $project,
        private readonly \Closure $server,
        private readonly \Closure $connectPolicy,
    ) {
    }

    /** The lookups over Kanboard's DB and models. */
    public static function fromContainer(Container $c): self
    {
        return new self(
            static fn (int $id): ?array => $c['db']->table('users')->columns('id', 'role', 'is_active')->eq('id', $id)->findOne() ?: null,
            static fn (int $projectId, int $userId): string => (string) $c['projectUserRoleModel']->getUserRole($projectId, $userId),
            static fn (int $id): ?array => $c['db']->table('projects')->columns('id', 'is_active')->eq('id', $id)->findOne() ?: null,
            static fn (int $id): ?array => $c['matineeServerModel']->get($id),
            static fn (): string => (new Settings($c))->connectPolicy(),
        );
    }

    public function canConnectServer(int $userId): bool
    {
        $user = $this->activeUser($userId);
        return $user !== null && (($this->connectPolicy)() !== Settings::POLICY_ADMINS || $user['role'] === Role::APP_ADMIN);
    }

    /** Owner or admin: disable (admin) and remove. */
    public function canManageServer(int $userId, array $server): bool
    {
        return $this->activeUser($userId) !== null
            && ((int) $server['owner_user_id'] === $userId || $this->isActiveAdmin($userId));
    }

    /**
     * The share kill switch: an active app admin. A users row counts only by its id; the live row decides, so a stale
     * session row of a demoted or disabled user does not.
     */
    public function canToggleShare(array|int $user): bool
    {
        return $this->isActiveAdmin(is_array($user) ? (int) ($user['id'] ?? 0) : $user);
    }

    /** An active app admin by the live users row (never the session's copy): the one admin check Matinee makes. */
    public function isActiveAdmin(int $userId): bool
    {
        return ($this->activeUser($userId)['role'] ?? null) === Role::APP_ADMIN;
    }

    /** The server owner, on an enabled server, still meeting the rule that let them create the share. */
    public function canEditShare(int $userId, array $share): bool
    {
        $server = $this->serverOf($share);
        if ($server === null || (int) $server['is_enabled'] !== 1 || (int) $server['owner_user_id'] !== $userId
            || $this->activeUser($userId) === null) {
            return false;
        }
        return match ($share['audience'] ?? null) {
            self::AUDIENCE_PROJECT => $this->activeProjectId($share) !== null
                && $this->standardRole($this->activeProjectId($share), $userId) === Role::PROJECT_MANAGER,
            self::AUDIENCE_EVERYONE => $this->isActiveAdmin($userId),
            default => false,
        };
    }

    /** The server owner, an app admin, or a manager (never a custom role) of the share's active project. */
    public function canUnlinkShare(int $userId, array $share): bool
    {
        if ($this->activeUser($userId) === null || ! $this->validAudience($share)) {
            return false;
        }
        if ($this->isActiveAdmin($userId)) {
            return true;
        }
        $server = $this->serverOf($share);
        if ($server !== null && (int) $server['owner_user_id'] === $userId) {
            return true;
        }
        $projectId = $share['audience'] === self::AUDIENCE_PROJECT ? $this->activeProjectId($share) : null;
        return $projectId !== null && $this->standardRole($projectId, $userId) === Role::PROJECT_MANAGER;
    }

    public function canBrowse(int $userId, array $share): bool
    {
        return $this->use($userId, $share) >= self::USE_BROWSE;
    }

    /** Start or control a watch room: member and up, or anyone on an everyone-share. Viewers only join. */
    public function canControlRoom(int $userId, array $share): bool
    {
        return $this->use($userId, $share) >= self::USE_MEMBER;
    }

    public function canEditPlaylist(int $userId, array $share): bool
    {
        return $this->use($userId, $share) >= self::USE_MEMBER;
    }

    /**
     * The shares in $shares this user may browse; with $projectId, only the project shares of that project.
     *
     * @param iterable<array<string, mixed>> $shares
     * @return list<array<string, mixed>>
     */
    public function visibleShares(int $userId, iterable $shares, ?int $projectId): array
    {
        $out = [];
        foreach ($shares as $share) {
            if ($projectId !== null && ($share['audience'] !== self::AUDIENCE_PROJECT || (int) $share['project_id'] !== $projectId)) {
                continue;
            }
            if ($this->canBrowse($userId, $share)) {
                $out[] = $share;
            }
        }
        return $out;
    }

    /**
     * A section key is reachable through the share when the server lists it now with an allow-listed type, and the
     * share covers it: chosen, or whole-server (which covers only what is live, S13).
     *
     * @param list<array{key: string, type: string}> $liveSections from PlexClient::sections()
     */
    public function sectionAllowed(array $share, string $sectionKey, array $liveSections): bool
    {
        if (preg_match(self::SECTION_KEY, $sectionKey) !== 1) {
            return false;
        }
        $live = false;
        foreach ($liveSections as $s) {
            if (($s['key'] ?? null) === $sectionKey && in_array($s['type'] ?? null, PlexClient::SECTION_TYPES, true)) {
                $live = true;
                break;
            }
        }
        if (! $live) {
            return false;
        }
        return (int) ($share['whole_server'] ?? 0) === 1 || in_array($sectionKey, $share['sections'] ?? [], true);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** 0 none, 1 browse, 2 member (browse, rooms, playlists). */
    private function use(int $userId, array $share): int
    {
        $server = $this->liveServer($share);
        if ($server === null || $this->activeUser($userId) === null) {
            return 0;
        }
        if ($share['audience'] === self::AUDIENCE_EVERYONE) {
            return self::USE_MEMBER;
        }
        $level = match ($this->role((int) $share['project_id'], $userId)) {
            '' => 0,
            Role::PROJECT_VIEWER => self::USE_BROWSE,
            default => self::USE_MEMBER, // member, manager, or a custom role (S6)
        };
        // The owner browses their own server's share whatever their role; rooms and playlists still need member+.
        return (int) $server['owner_user_id'] === $userId ? max($level, self::USE_BROWSE) : $level;
    }

    /** The share's server when the share is live (see the class doc), else null. */
    private function liveServer(array $share): ?array
    {
        if ((int) ($share['is_enabled'] ?? 0) !== 1 || ! $this->validAudience($share)) {
            return null;
        }
        $server = $this->serverOf($share);
        if ($server === null || (int) $server['is_enabled'] !== 1 || $this->activeUser((int) $server['owner_user_id']) === null) {
            return null;
        }
        $audienceStands = $share['audience'] === self::AUDIENCE_EVERYONE
            ? $this->isActiveAdmin((int) ($share['created_by'] ?? 0))
            : $this->activeProjectId($share) !== null;
        return $audienceStands ? $server : null;
    }

    private function validAudience(array $share): bool
    {
        return match ($share['audience'] ?? null) {
            self::AUDIENCE_PROJECT => (int) ($share['project_id'] ?? 0) > 0,
            self::AUDIENCE_EVERYONE => ($share['project_id'] ?? null) === null,
            default => false,
        };
    }

    private function serverOf(array $share): ?array
    {
        return ($this->server)((int) ($share['server_id'] ?? 0));
    }

    /** The share's project id when that project exists and is active. */
    private function activeProjectId(array $share): ?int
    {
        $id = (int) ($share['project_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $project = ($this->project)($id);
        return $project && (int) $project['is_active'] === 1 ? $id : null;
    }

    private function role(int $projectId, int $userId): string
    {
        return ($this->projectRole)($projectId, $userId);
    }

    /** The role when it is one of Kanboard's three; a custom role reads as member. */
    private function standardRole(int $projectId, int $userId): string
    {
        $role = $this->role($projectId, $userId);
        return in_array($role, [Role::PROJECT_MANAGER, Role::PROJECT_MEMBER, Role::PROJECT_VIEWER, ''], true) ? $role : Role::PROJECT_MEMBER;
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
