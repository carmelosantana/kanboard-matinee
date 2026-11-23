<?php

use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Matinee\Model\MatineePolicy;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MatineePolicy (plan Task 11; spec "Light scope", §11 S6) driven by in-memory lookups, so every row runs without a
 * DB. ServerModelTest covers the container wiring against a real DB.
 *
 * The world: servers 50 (private) and 51 (shared) belong to OWNER, an app user; server 52 (private) belongs to ADMIN.
 * Abilities are written B R P M T: browse, control a room, edit a playlist, manage (verify, remove, disable), toggle
 * sharing. A dash is a refusal.
 */
class MatineePolicyTest extends TestCase
{
    private const OWNER = 1;
    private const ADMIN = 2;
    private const OTHER = 3;
    private const APP_MANAGER = 4;
    private const GHOST = 99;

    private const PRIVATE = 50;
    private const SHARED = 51;
    private const ADMIN_PRIVATE = 52;

    /** The default world; each table row edits a copy of it. */
    private static function world(): array
    {
        $u = static fn (string $role = Role::APP_USER): array => ['role' => $role, 'is_active' => 1];
        $s = static fn (int $id, int $owner, int $shared): array => ['id' => $id, 'owner_user_id' => $owner, 'is_enabled' => 1, 'is_shared' => $shared];
        return [
            'users' => [
                self::OWNER => $u(),
                self::ADMIN => $u(Role::APP_ADMIN),
                self::OTHER => $u(),
                self::APP_MANAGER => $u(Role::APP_MANAGER),
            ],
            'servers' => [
                self::PRIVATE => $s(self::PRIVATE, self::OWNER, 0),
                self::SHARED => $s(self::SHARED, self::OWNER, 1),
                self::ADMIN_PRIVATE => $s(self::ADMIN_PRIVATE, self::ADMIN, 0),
            ],
            'policy' => 'everyone',
        ];
    }

    private static function policy(array $w): MatineePolicy
    {
        return new MatineePolicy(
            static fn (int $id): ?array => $w['users'][$id] ?? null,
            static fn (int $id): ?array => $w['servers'][$id] ?? null,
            static fn (): string => $w['policy'],
        );
    }

    private static function abilities(MatineePolicy $p, int $user, array $server): string
    {
        return ($p->canBrowse($user, $server) ? 'B' : '-')
            .($p->canControlRoom($user, $server) ? 'R' : '-')
            .($p->canEditPlaylist($user, $server) ? 'P' : '-')
            .($p->canManageServer($user, $server) ? 'M' : '-')
            .($p->canToggleShared($user, $server) ? 'T' : '-');
    }

    /** @dataProvider table */
    #[DataProvider('table')]
    public function testAbilities(int $serverId, int $user, ?callable $tweak, string $expected): void
    {
        $w = self::world();
        // The caller's copy of the row, as loaded before the tweak: the policy must judge by the live row.
        $server = $w['servers'][$serverId];
        if ($tweak !== null) {
            $tweak($w);
        }
        $this->assertSame($expected, self::abilities(self::policy($w), $user, $server));
    }

    public static function table(): array
    {
        $deactivate = static fn (int $id): \Closure => static function (array &$w) use ($id) {
            $w['users'][$id]['is_active'] = 0;
        };
        $disable = static fn (int $id): \Closure => static function (array &$w) use ($id) {
            $w['servers'][$id]['is_enabled'] = 0;
        };
        return [
            // The owner has everything on their own server, shared or not.
            'owner, private' => [self::PRIVATE, self::OWNER, null, 'BRPMT'],
            'owner, shared' => [self::SHARED, self::OWNER, null, 'BRPMT'],
            // Sharing is the whole server for every active user. Kills "browse ignores is_shared".
            'other user, shared' => [self::SHARED, self::OTHER, null, 'BRP--'],
            'other user, private' => [self::PRIVATE, self::OTHER, null, '-----'],
            'app manager is an ordinary user, shared' => [self::SHARED, self::APP_MANAGER, null, 'BRP--'],
            'app manager is not an admin, private' => [self::PRIVATE, self::APP_MANAGER, null, '-----'],
            'unknown user' => [self::SHARED, self::GHOST, null, '-----'],

            // An admin manages any server but browses only what any user may, and never flips someone's sharing.
            'admin, private server' => [self::PRIVATE, self::ADMIN, null, '---M-'],
            'admin, shared server' => [self::SHARED, self::ADMIN, null, 'BRPM-'],
            'admin, their own server' => [self::ADMIN_PRIVATE, self::ADMIN, null, 'BRPMT'],
            'demoted admin' => [self::PRIVATE, self::ADMIN, static function (array &$w) {
                $w['users'][self::ADMIN]['role'] = Role::APP_USER;
            }, '-----'],
            'inactive admin' => [self::SHARED, self::ADMIN, $deactivate(self::ADMIN), '-----'],

            // S6: users.is_active is read on every call.
            'inactive user, shared' => [self::SHARED, self::OTHER, $deactivate(self::OTHER), '-----'],
            'inactive owner' => [self::SHARED, self::OWNER, $deactivate(self::OWNER), '-----'],
            'other user, shared server of an inactive owner' => [self::SHARED, self::OTHER, $deactivate(self::OWNER), '-----'],
            'admin still manages an inactive owner\'s server' => [self::SHARED, self::ADMIN, $deactivate(self::OWNER), '---M-'],

            // A disabled server: nothing but management; the owner may not toggle it either.
            'disabled server, owner' => [self::SHARED, self::OWNER, $disable(self::SHARED), '---M-'],
            'disabled server, other user' => [self::SHARED, self::OTHER, $disable(self::SHARED), '-----'],
            'disabled server, admin' => [self::SHARED, self::ADMIN, $disable(self::SHARED), '---M-'],

            // The live row decides, not the caller's copy.
            'unshared since the row was loaded' => [self::SHARED, self::OTHER, static function (array &$w) {
                $w['servers'][self::SHARED]['is_shared'] = 0;
            }, '-----'],
            'removed since the row was loaded' => [self::SHARED, self::OWNER, static function (array &$w) {
                unset($w['servers'][self::SHARED]);
            }, '-----'],
            'owner changed since the row was loaded' => [self::PRIVATE, self::OWNER, static function (array &$w) {
                $w['servers'][self::PRIVATE]['owner_user_id'] = self::OTHER;
            }, '-----'],
        ];
    }

    /** A caller's array claiming the server is shared or theirs grants nothing: only its id is read. */
    public function testOnlyTheIdOfTheCallersArrayIsRead(): void
    {
        $p = self::policy(self::world());
        $forged = ['id' => self::PRIVATE, 'owner_user_id' => self::OTHER, 'is_shared' => 1, 'is_enabled' => 1];
        $this->assertSame('-----', self::abilities($p, self::OTHER, $forged));
        $this->assertSame('-----', self::abilities($p, self::OTHER, []));
    }

    /** @dataProvider connectTable */
    #[DataProvider('connectTable')]
    public function testCanConnectServer(int $user, string $policy, ?int $inactive, bool $expected): void
    {
        $w = self::world();
        $w['policy'] = $policy;
        if ($inactive !== null) {
            $w['users'][$inactive]['is_active'] = 0;
        }
        $this->assertSame($expected, self::policy($w)->canConnectServer($user));
    }

    public static function connectTable(): array
    {
        return [
            'user, policy everyone' => [self::OTHER, 'everyone', null, true],
            'user, policy admins' => [self::OTHER, 'admins', null, false],
            'app manager, policy admins' => [self::APP_MANAGER, 'admins', null, false],
            'admin, policy admins' => [self::ADMIN, 'admins', null, true],
            'inactive user' => [self::OTHER, 'everyone', self::OTHER, false],
            'inactive admin' => [self::ADMIN, 'admins', self::ADMIN, false],
            'unknown user' => [self::GHOST, 'everyone', null, false],
        ];
    }

    /** @dataProvider activeAdminTable */
    #[DataProvider('activeAdminTable')]
    public function testIsActiveAdmin(int $user, ?\Closure $tweak, bool $expected): void
    {
        $w = self::world();
        if ($tweak !== null) {
            $tweak($w);
        }
        $this->assertSame($expected, self::policy($w)->isActiveAdmin($user));
    }

    public static function activeAdminTable(): array
    {
        return [
            'admin' => [self::ADMIN, null, true],
            'app manager' => [self::APP_MANAGER, null, false],
            'app user' => [self::OTHER, null, false],
            'unknown user' => [self::GHOST, null, false],
            'no user' => [0, null, false],
            'inactive admin' => [self::ADMIN, static function (array &$w) {
                $w['users'][self::ADMIN]['is_active'] = 0;
            }, false],
            'demoted admin' => [self::ADMIN, static function (array &$w) {
                $w['users'][self::ADMIN]['role'] = Role::APP_USER;
            }, false],
        ];
    }

    /** @dataProvider sectionTable */
    #[DataProvider('sectionTable')]
    public function testSectionAllowed(string $key, bool $expected): void
    {
        // PlexClient::sections() has already dropped every type outside the allow-list.
        $live = [
            ['key' => '1', 'title' => 'Movies', 'type' => 'movie'],
            ['key' => '2', 'title' => 'Shows', 'type' => 'show'],
            ['key' => '5', 'title' => 'Music', 'type' => 'artist'],
        ];
        $this->assertSame($expected, self::policy(self::world())->sectionAllowed($live, $key));
    }

    public static function sectionTable(): array
    {
        return [
            'live' => ['2', true],
            'another live one' => ['5', true],
            'not live' => ['7', false],
            'empty key' => ['', false],
            'leading zero is another key' => ['01', false],
            'trailing newline' => ["1\n", false],
        ];
    }

    public function testNoLiveSectionsAllowsNothing(): void
    {
        $this->assertFalse(self::policy(self::world())->sectionAllowed([], '1'));
    }
}
