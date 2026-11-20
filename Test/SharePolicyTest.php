<?php

use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Matinee\Model\SharePolicy;
use PHPUnit\Framework\TestCase;

/**
 * SharePolicy (plan Task 8; spec §5, §11 S5, S6, S13, S15; decision map R8) driven by in-memory lookups, so every
 * role × audience × flag row runs without a DB. ShareModelTest covers the container wiring against a real DB.
 *
 * The world: server 50 (owner 1, an app user) holds the project share for project 100; server 51 (owner 8, an app
 * admin) holds the everyone-share. Abilities are written B R P U E: browse, control a room, edit a playlist, unlink,
 * edit the share's scope. A dash is a refusal.
 */
class SharePolicyTest extends TestCase
{
    private const OWNER = 1;
    private const ADMIN = 2;
    private const VIEWER = 3;
    private const MEMBER = 4;
    private const MANAGER = 5;
    private const CUSTOM = 6;
    private const OUTSIDER = 7;
    private const ADMIN_OWNER = 8;
    private const ADMIN_MEMBER = 9;
    private const GHOST = 99;

    private const PROJECT = 100;
    private const SERVER = 50;
    private const ADMIN_SERVER = 51;

    /** The default world; each table row edits a copy of it. */
    private static function world(): array
    {
        $u = static fn (string $role = Role::APP_USER): array => ['role' => $role, 'is_active' => 1];
        return [
            'users' => [
                self::OWNER => $u(),
                self::ADMIN => $u(Role::APP_ADMIN),
                self::VIEWER => $u(),
                self::MEMBER => $u(),
                self::MANAGER => $u(),
                self::CUSTOM => $u(),
                self::OUTSIDER => $u(),
                self::ADMIN_OWNER => $u(Role::APP_ADMIN),
                self::ADMIN_MEMBER => $u(Role::APP_ADMIN),
            ],
            'roles' => [
                self::PROJECT => [
                    self::VIEWER => Role::PROJECT_VIEWER,
                    self::MEMBER => Role::PROJECT_MEMBER,
                    self::MANAGER => Role::PROJECT_MANAGER,
                    self::CUSTOM => 'Reviewer',
                    self::ADMIN_MEMBER => Role::PROJECT_MEMBER,
                ],
            ],
            'projects' => [self::PROJECT => ['id' => self::PROJECT, 'is_active' => 1]],
            'servers' => [
                self::SERVER => ['id' => self::SERVER, 'owner_user_id' => self::OWNER, 'is_enabled' => 1],
                self::ADMIN_SERVER => ['id' => self::ADMIN_SERVER, 'owner_user_id' => self::ADMIN_OWNER, 'is_enabled' => 1],
            ],
            'policy' => 'everyone',
        ];
    }

    private static function projectShare(): array
    {
        return ['id' => 1, 'server_id' => self::SERVER, 'created_by' => self::OWNER, 'audience' => 'project',
            'project_id' => self::PROJECT, 'whole_server' => 0, 'is_enabled' => 1, 'sections' => ['1']];
    }

    private static function everyoneShare(): array
    {
        return ['id' => 2, 'server_id' => self::ADMIN_SERVER, 'created_by' => self::ADMIN_OWNER, 'audience' => 'everyone',
            'project_id' => null, 'whole_server' => 1, 'is_enabled' => 1, 'sections' => []];
    }

    private static function policy(array $w): SharePolicy
    {
        return new SharePolicy(
            static fn (int $id): ?array => $w['users'][$id] ?? null,
            static fn (int $projectId, int $userId): string => $w['roles'][$projectId][$userId] ?? '',
            static fn (int $id): ?array => $w['projects'][$id] ?? null,
            static fn (int $id): ?array => $w['servers'][$id] ?? null,
            static fn (): string => $w['policy'],
        );
    }

    private static function abilities(SharePolicy $p, int $user, array $share): string
    {
        return ($p->canBrowse($user, $share) ? 'B' : '-')
            .($p->canControlRoom($user, $share) ? 'R' : '-')
            .($p->canEditPlaylist($user, $share) ? 'P' : '-')
            .($p->canUnlinkShare($user, $share) ? 'U' : '-')
            .($p->canEditShare($user, $share) ? 'E' : '-');
    }

    /** @dataProvider table */
    public function testAbilities(string $audience, int $user, ?callable $tweak, string $expected): void
    {
        $w = self::world();
        $share = $audience === 'project' ? self::projectShare() : self::everyoneShare();
        if ($tweak !== null) {
            $tweak($w, $share);
        }
        $this->assertSame($expected, self::abilities(self::policy($w), $user, $share));
    }

    public function table(): array
    {
        $disable = static fn (int $id): \Closure => static function (array &$w) use ($id) {
            $w['users'][$id]['is_active'] = 0;
        };
        return [
            // Project share, everything live (R8 role table).
            'project viewer browses only' => ['project', self::VIEWER, null, 'B----'],
            'project member: rooms and playlists' => ['project', self::MEMBER, null, 'BRP--'],
            'project manager also unlinks' => ['project', self::MANAGER, null, 'BRPU-'],
            // S6: a custom role counts as member, never manager. Kills "custom role treated as manager".
            'custom role is a member, never a manager' => ['project', self::CUSTOM, null, 'BRP--'],
            'non-member gets nothing' => ['project', self::OUTSIDER, null, '-----'],
            'unknown user gets nothing' => ['project', self::GHOST, null, '-----'],
            // Ruling: the owner browses live shares of their own server, member of the project or not, but rooms and
            // playlists need a current member+ role like anyone else. Editing scope re-checks the manager rule (S6).
            // Kills "restore the owner's full bypass".
            'server owner, not a project member' => ['project', self::OWNER, null, 'B--U-'],
            'server owner as a project viewer' => ['project', self::OWNER, static function (array &$w) {
                $w['roles'][self::PROJECT][self::OWNER] = Role::PROJECT_VIEWER;
            }, 'B--U-'],
            'server owner as a project member' => ['project', self::OWNER, static function (array &$w) {
                $w['roles'][self::PROJECT][self::OWNER] = Role::PROJECT_MEMBER;
            }, 'BRPU-'],
            // §5: an admin with no project role gets no browse; they still unlink. Kills "admin browses".
            'app admin without a project role only unlinks' => ['project', self::ADMIN, null, '---U-'],
            'app admin with a member role' => ['project', self::ADMIN_MEMBER, null, 'BRPU-'],

            // S6: users.is_active is read on every call.
            'disabled viewer' => ['project', self::VIEWER, $disable(self::VIEWER), '-----'],
            'disabled manager cannot unlink' => ['project', self::MANAGER, $disable(self::MANAGER), '-----'],
            'disabled owner' => ['project', self::OWNER, static function (array &$w) {
                $w['users'][self::OWNER]['is_active'] = 0;
                $w['roles'][self::PROJECT][self::OWNER] = Role::PROJECT_MANAGER;
            }, '-----'],
            'owner with a custom role cannot edit' => ['project', self::OWNER, static function (array &$w) {
                $w['roles'][self::PROJECT][self::OWNER] = 'Reviewer';
            }, 'BRPU-'],
            'disabled admin' => ['project', self::ADMIN, $disable(self::ADMIN), '-----'],
            // S6: a disabled owner makes their shares inert. Kills "skip the owner-active check".
            'member of a disabled owner\'s share' => ['project', self::MEMBER, $disable(self::OWNER), '-----'],
            'manager still unlinks a disabled owner\'s share' => ['project', self::MANAGER, $disable(self::OWNER), '---U-'],

            // Disabled server and disabled share: nothing but removal.
            'disabled server: member' => ['project', self::MEMBER, static function (array &$w) {
                $w['servers'][self::SERVER]['is_enabled'] = 0;
            }, '-----'],
            'disabled server: owner manager unlinks, cannot edit' => ['project', self::OWNER, static function (array &$w) {
                $w['servers'][self::SERVER]['is_enabled'] = 0;
                $w['roles'][self::PROJECT][self::OWNER] = Role::PROJECT_MANAGER;
            }, '---U-'],
            'missing server: member' => ['project', self::MEMBER, static function (array &$w) {
                unset($w['servers'][self::SERVER]);
            }, '-----'],
            'missing server: owner' => ['project', self::OWNER, static function (array &$w) {
                unset($w['servers'][self::SERVER]);
            }, '-----'],
            'disabled share: member' => ['project', self::MEMBER, static function (array &$w, array &$s) {
                $s['is_enabled'] = 0;
            }, '-----'],
            'disabled share: manager unlinks' => ['project', self::MANAGER, static function (array &$w, array &$s) {
                $s['is_enabled'] = 0;
            }, '---U-'],
            'disabled share: owner manager unlinks and edits scope' => ['project', self::OWNER, static function (array &$w, array &$s) {
                $s['is_enabled'] = 0;
                $w['roles'][self::PROJECT][self::OWNER] = Role::PROJECT_MANAGER;
            }, '---UE'],

            // S6: an inactive project allows nothing, managers included; owner and admin may still unlink.
            'inactive project: member' => ['project', self::MEMBER, static function (array &$w) {
                $w['projects'][self::PROJECT]['is_active'] = 0;
            }, '-----'],
            'inactive project: manager' => ['project', self::MANAGER, static function (array &$w) {
                $w['projects'][self::PROJECT]['is_active'] = 0;
            }, '-----'],
            'inactive project: owner manager only unlinks' => ['project', self::OWNER, static function (array &$w) {
                $w['projects'][self::PROJECT]['is_active'] = 0;
                $w['roles'][self::PROJECT][self::OWNER] = Role::PROJECT_MANAGER;
            }, '---U-'],
            'inactive project: admin' => ['project', self::ADMIN, static function (array &$w) {
                $w['projects'][self::PROJECT]['is_active'] = 0;
            }, '---U-'],
            'deleted project: member' => ['project', self::MEMBER, static function (array &$w) {
                unset($w['projects'][self::PROJECT]);
            }, '-----'],

            // S6: the manager rule is re-checked on edit; an owner who has become a manager may edit.
            'owner who is the project manager edits' => ['project', self::OWNER, static function (array &$w) {
                $w['roles'][self::PROJECT][self::OWNER] = Role::PROJECT_MANAGER;
            }, 'BRPUE'],
            // Only the server owner edits scope; a manager does not, even a manager who is an admin.
            'admin manager does not edit another owner\'s share' => ['project', self::ADMIN_MEMBER, static function (array &$w) {
                $w['roles'][self::PROJECT][self::ADMIN_MEMBER] = Role::PROJECT_MANAGER;
            }, 'BRPU-'],
            // A project_id the URL might carry plays no part: a share whose row names another project is judged by it.
            'role in another project does not count' => ['project', self::MEMBER, static function (array &$w, array &$s) {
                $s['project_id'] = 101;
                $w['projects'][101] = ['id' => 101, 'is_active' => 1];
            }, '-----'],

            // Everyone-share: any active user browses, joins rooms and edits playlists (R8).
            'everyone: an outsider' => ['everyone', self::OUTSIDER, null, 'BRP--'],
            'everyone: a project viewer' => ['everyone', self::VIEWER, null, 'BRP--'],
            'everyone: the admin owner' => ['everyone', self::ADMIN_OWNER, null, 'BRPUE'],
            'everyone: another admin' => ['everyone', self::ADMIN, null, 'BRPU-'],
            'everyone: a disabled user' => ['everyone', self::OUTSIDER, $disable(self::OUTSIDER), '-----'],
            'everyone: unknown user' => ['everyone', self::GHOST, null, '-----'],
            'everyone: disabled share' => ['everyone', self::OUTSIDER, static function (array &$w, array &$s) {
                $s['is_enabled'] = 0;
            }, '-----'],
            'everyone: disabled server' => ['everyone', self::OUTSIDER, static function (array &$w) {
                $w['servers'][self::ADMIN_SERVER]['is_enabled'] = 0;
            }, '-----'],
            'everyone: disabled owner' => ['everyone', self::OUTSIDER, $disable(self::ADMIN_OWNER), '-----'],
            // S6: an everyone-share stops working when its creator is no longer an app admin. Kills "skip creator check".
            'everyone: creator lost admin, outsider' => ['everyone', self::OUTSIDER, static function (array &$w) {
                $w['users'][self::ADMIN_OWNER]['role'] = Role::APP_USER;
            }, '-----'],
            'everyone: creator lost admin, owner only unlinks' => ['everyone', self::ADMIN_OWNER, static function (array &$w) {
                $w['users'][self::ADMIN_OWNER]['role'] = Role::APP_MANAGER;
            }, '---U-'],
            'everyone: creator lost admin, an admin unlinks' => ['everyone', self::ADMIN, static function (array &$w) {
                $w['users'][self::ADMIN_OWNER]['role'] = Role::APP_USER;
            }, '---U-'],
            'everyone: creator gone' => ['everyone', self::OUTSIDER, static function (array &$w, array &$s) {
                $s['created_by'] = self::GHOST;
            }, '-----'],
            'everyone: creator another admin who was disabled' => ['everyone', self::OUTSIDER, static function (array &$w, array &$s) {
                $s['created_by'] = self::ADMIN;
                $w['users'][self::ADMIN]['is_active'] = 0;
            }, '-----'],

            // A malformed row is refused outright.
            'unknown audience' => ['project', self::OWNER, static function (array &$w, array &$s) {
                $s['audience'] = 'public';
            }, '-----'],
            'project share without a project' => ['project', self::OWNER, static function (array &$w, array &$s) {
                $s['project_id'] = null;
            }, '-----'],
        ];
    }

    /** @dataProvider connectTable */
    public function testCanConnectServer(int $user, string $policy, ?int $disabled, bool $expected): void
    {
        $w = self::world();
        $w['policy'] = $policy;
        if ($disabled !== null) {
            $w['users'][$disabled]['is_active'] = 0;
        }
        $this->assertSame($expected, self::policy($w)->canConnectServer($user));
    }

    public function connectTable(): array
    {
        return [
            'user, policy everyone' => [self::OUTSIDER, 'everyone', null, true],
            'user, policy admins' => [self::OUTSIDER, 'admins', null, false],
            'admin, policy admins' => [self::ADMIN, 'admins', null, true],
            'disabled user' => [self::OUTSIDER, 'everyone', self::OUTSIDER, false],
            'disabled admin' => [self::ADMIN, 'admins', self::ADMIN, false],
            'unknown user' => [self::GHOST, 'everyone', null, false],
        ];
    }

    /** @dataProvider manageTable */
    public function testCanManageServer(int $user, ?int $disabled, bool $expected): void
    {
        $w = self::world();
        if ($disabled !== null) {
            $w['users'][$disabled]['is_active'] = 0;
        }
        $this->assertSame($expected, self::policy($w)->canManageServer($user, $w['servers'][self::SERVER]));
    }

    public function manageTable(): array
    {
        return [
            'owner' => [self::OWNER, null, true],
            'admin' => [self::ADMIN, null, true],
            'project manager' => [self::MANAGER, null, false],
            'another admin-owned server\'s owner' => [self::ADMIN_OWNER, self::ADMIN_OWNER, false],
            'disabled owner' => [self::OWNER, self::OWNER, false],
            'disabled admin' => [self::ADMIN, self::ADMIN, false],
        ];
    }

    /** @dataProvider toggleTable */
    public function testCanToggleShare(int|array $user, ?\Closure $tweak, bool $expected): void
    {
        $w = self::world();
        $w['users'][10] = ['role' => Role::APP_MANAGER, 'is_active' => 1];
        if ($tweak !== null) {
            $tweak($w);
        }
        $this->assertSame($expected, self::policy($w)->canToggleShare($user));
    }

    public function toggleTable(): array
    {
        return [
            'admin' => [self::ADMIN, null, true],
            'admin as a row' => [['id' => self::ADMIN, 'role' => Role::APP_ADMIN, 'is_active' => 1], null, true],
            'app manager' => [10, null, false],
            'app user' => [self::OUTSIDER, null, false],
            'server owner' => [self::OWNER, null, false],
            'unknown user' => [self::GHOST, null, false],
            'inactive admin' => [self::ADMIN, static function (array &$w) {
                $w['users'][self::ADMIN]['is_active'] = 0;
            }, false],
            // A stale session row does not count: the live row decides.
            'stale admin row of a demoted user' => [['id' => self::OUTSIDER, 'role' => Role::APP_ADMIN, 'is_active' => 1], null, false],
            'row without an id' => [['role' => Role::APP_ADMIN, 'is_active' => 1], null, false],
        ];
    }

    /** @dataProvider sectionTable */
    public function testSectionAllowed(bool $whole, array $chosen, string $key, bool $expected): void
    {
        $live = [
            ['key' => '1', 'title' => 'Movies', 'type' => 'movie'],
            ['key' => '2', 'title' => 'Shows', 'type' => 'show'],
            ['key' => '5', 'title' => 'Music', 'type' => 'artist'],
            ['key' => '9', 'title' => 'Photos', 'type' => 'photo'],
            ['key' => '3', 'title' => 'Clips', 'type' => 'clip'],
        ];
        $share = ['whole_server' => $whole ? 1 : 0, 'sections' => $chosen] + self::projectShare();
        $this->assertSame($expected, self::policy(self::world())->sectionAllowed($share, $key, $live));
    }

    public function sectionTable(): array
    {
        return [
            'chosen and live' => [false, ['1', '2'], '2', true],
            'chosen, no longer live' => [false, ['1', '7'], '7', false],
            'live, not chosen' => [false, ['1'], '2', false],
            'whole server, live movie' => [true, [], '1', true],
            'whole server, live photo' => [true, [], '9', true],
            'whole server, not live' => [true, [], '7', false],
            // S13: whole-server covers only allow-listed section types.
            'whole server, live but clip type' => [true, [], '3', false],
            'chosen clip type is still refused' => [false, ['3'], '3', false],
            'malformed key' => [true, [], '1a', false],
            'empty key' => [true, [], '', false],
            'key with a newline' => [false, ["1\n"], "1\n", false],
            'twenty-one digits' => [true, [], '123456789012345678901', false],
        ];
    }

    public function testVisibleSharesFiltersByBrowseAndProject(): void
    {
        $w = self::world();
        $w['projects'][101] = ['id' => 101, 'is_active' => 1];
        $w['roles'][101][self::MEMBER] = Role::PROJECT_MEMBER;
        $other = ['id' => 3, 'project_id' => 101] + self::projectShare();
        $hidden = ['id' => 4, 'is_enabled' => 0] + self::projectShare();
        $shares = [self::projectShare(), self::everyoneShare(), $other, $hidden];
        $p = self::policy($w);

        $ids = static fn (array $list): array => array_map(static fn (array $s): int => $s['id'], $list);
        $this->assertSame([1, 2, 3], $ids($p->visibleShares(self::MEMBER, $shares, null)));
        $this->assertSame([1], $ids($p->visibleShares(self::MEMBER, $shares, self::PROJECT)));
        $this->assertSame([3], $ids($p->visibleShares(self::MEMBER, $shares, 101)));
        $this->assertSame([2], $ids($p->visibleShares(self::OUTSIDER, $shares, null)));
        $this->assertSame([], $ids($p->visibleShares(self::ADMIN, $shares, self::PROJECT)));
    }
}
