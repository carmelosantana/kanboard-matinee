<?php

namespace Kanboard\Plugin\Matinee\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;

/**
 * Shares and their sections (spec §5; §11 S5, S6, S13). Every ability is SharePolicy's: create asks canEditShare of
 * the share it would write, so creating and editing follow one rule (the server owner, on an enabled server, managing
 * the active project or an active app admin for everyone).
 *
 * - Authorization failures, and ids that do not exist, read the same: MatineeForbiddenException.
 * - A well-authorized request with bad content is MatineeShareException('matinee.invalid_share'); nothing is echoed.
 * - Chosen section keys are checked against the server's live list (ServerModel::clientFor) at save time; a failed
 *   lookup is the generic MatineeConnectException. A whole-server share stores no section rows.
 * - At most one everyone-share per server and one share per (server, project), checked here on every driver
 *   (MySQL has no partial unique index), with the DB's unique keys as the backstop.
 */
final class ShareModel extends Base
{
    public const TABLE = 'matinee_shares';
    public const SECTIONS_TABLE = 'matinee_share_sections';
    public const COLUMNS = ['id', 'server_id', 'created_by', 'audience', 'project_id', 'whole_server', 'is_enabled', 'created_at'];

    /** More chosen sections than any real server has is malformed input. */
    private const MAX_SECTIONS = 200;

    /** @param list<string> $sections chosen section keys; empty for a whole-server share */
    public function create(int $actorId, int $serverId, string $audience, ?int $projectId, bool $wholeServer, array $sections): int
    {
        $isProject = $audience === SharePolicy::AUDIENCE_PROJECT;
        if ((! $isProject && $audience !== SharePolicy::AUDIENCE_EVERYONE)
            || ($isProject && ($projectId === null || $projectId <= 0))
            || (! $isProject && $projectId !== null)) {
            throw new MatineeShareException();
        }
        $server = $this->serverModel()->get($serverId);
        $share = [
            'server_id' => $serverId,
            'created_by' => $actorId,
            'audience' => $audience,
            'project_id' => $isProject ? $projectId : null,
            'whole_server' => $wholeServer ? 1 : 0,
            'is_enabled' => 1,
        ];
        if ($server === null || ! $this->policy()->canEditShare($actorId, $share)) {
            throw new MatineeForbiddenException();
        }
        $sections = $this->checkSections($wholeServer, $sections);
        if ($this->exists($serverId, $share['project_id'])) {
            throw new MatineeShareException();
        }
        $this->checkLive($server, $sections);

        $this->db->startTransaction();
        try {
            if (! $this->db->table(self::TABLE)->insert($share + ['created_at' => time()])) {
                throw new \RuntimeException('insert failed');
            }
            $id = (int) $this->db->getLastId();
            $this->writeSections($id, $sections);
            $this->db->closeTransaction();
        } catch (\Throwable) {
            // A concurrent create that won the unique key lands here too.
            $this->db->cancelTransaction();
            throw new MatineeShareException();
        }
        return $id;
    }

    /** The server owner re-scopes the share; the creation rule is checked again (S6). */
    public function update(int $shareId, int $actorId, bool $wholeServer, array $sections): void
    {
        $share = $this->get($shareId);
        if ($share === null || ! $this->policy()->canEditShare($actorId, $share)) {
            throw new MatineeForbiddenException();
        }
        $sections = $this->checkSections($wholeServer, $sections);
        $this->checkLive($this->serverModel()->get((int) $share['server_id']) ?? throw new MatineeForbiddenException(), $sections);

        $this->db->startTransaction();
        try {
            $this->db->table(self::TABLE)->eq('id', $shareId)->update(['whole_server' => $wholeServer ? 1 : 0]);
            $this->db->table(self::SECTIONS_TABLE)->eq('share_id', $shareId)->remove();
            $this->writeSections($shareId, $sections);
            $this->db->closeTransaction();
        } catch (\Throwable) {
            $this->db->cancelTransaction();
            throw new MatineeShareException();
        }
    }

    /** The server owner, the share's project managers, or an app admin. Sections go with it. */
    public function unlink(int $shareId, int $actorId): void
    {
        $share = $this->get($shareId);
        if ($share === null || ! $this->policy()->canUnlinkShare($actorId, $share)) {
            throw new MatineeForbiddenException();
        }
        $this->db->startTransaction();
        $this->db->table(self::SECTIONS_TABLE)->eq('share_id', $shareId)->remove();
        $this->db->table(self::TABLE)->eq('id', $shareId)->remove();
        $this->db->closeTransaction();
    }

    /** Active app admin only: the share kill switch. */
    public function setEnabled(int $shareId, bool $on, int $actorId): void
    {
        $actor = $this->db->table('users')->columns('role', 'is_active')->eq('id', $actorId)->findOne();
        if (! $actor || (int) $actor['is_active'] !== 1 || $actor['role'] !== Role::APP_ADMIN || $this->get($shareId) === null) {
            throw new MatineeForbiddenException();
        }
        $this->db->table(self::TABLE)->eq('id', $shareId)->update(['is_enabled' => $on ? 1 : 0]);
    }

    /** @return array<string, mixed>|null the share with its chosen `sections` (list of keys) */
    public function get(int $id): ?array
    {
        $share = $this->db->table(self::TABLE)->columns(...self::COLUMNS)->eq('id', $id)->findOne();
        return $share ? $this->withSections([$share])[0] : null;
    }

    /**
     * Every share of a server, for its owner's and the admin's pages. The caller checks
     * SharePolicy::canManageServer first.
     *
     * @return list<array<string, mixed>>
     */
    public function listForServer(int $serverId): array
    {
        return $this->withSections($this->db->table(self::TABLE)->columns(...self::COLUMNS)->eq('server_id', $serverId)->asc('id')->findAll());
    }

    /**
     * The shares this user may browse now (SharePolicy::canBrowse), computed per call. With $projectId, only that
     * project's shares; without, every share they reach, everyone-shares included.
     *
     * @return list<array<string, mixed>>
     */
    public function listVisible(int $userId, ?int $projectId): array
    {
        $query = $this->db->table(self::TABLE)->columns(...self::COLUMNS)->eq('is_enabled', 1)->asc('id');
        if ($projectId !== null) {
            $query->eq('audience', SharePolicy::AUDIENCE_PROJECT)->eq('project_id', $projectId);
        }
        return $this->policy()->visibleShares($userId, $this->withSections($query->findAll()), $projectId);
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function serverModel(): ServerModel
    {
        return $this->container['matineeServerModel'];
    }

    private function policy(): SharePolicy
    {
        return $this->container['matineeSharePolicy'];
    }

    /**
     * Whole-server takes no keys; otherwise 1..MAX_SECTIONS distinct keys matching ^\d{1,20}$.
     *
     * @return list<string> the keys in natural order
     */
    private function checkSections(bool $wholeServer, array $sections): array
    {
        if ($wholeServer) {
            if ($sections !== []) {
                throw new MatineeShareException();
            }
            return [];
        }
        if ($sections === [] || count($sections) > self::MAX_SECTIONS || ! array_is_list($sections)) {
            throw new MatineeShareException();
        }
        foreach ($sections as $key) {
            if (! is_string($key) || preg_match(SharePolicy::SECTION_KEY, $key) !== 1) {
                throw new MatineeShareException();
            }
        }
        if (count(array_unique($sections)) !== count($sections)) {
            throw new MatineeShareException();
        }
        sort($sections, SORT_NATURAL);
        return $sections;
    }

    /** Every chosen key must be on the server now, with an allow-listed type. */
    private function checkLive(array $server, array $sections): void
    {
        if ($sections === []) {
            return;
        }
        try {
            $live = $this->serverModel()->clientFor($server)->sections();
        } catch (\Throwable) {
            $this->logger->info('Matinee: share sections lookup failed (server '.(int) $server['id'].')');
            throw new MatineeConnectException();
        }
        $everything = ['whole_server' => 1];
        foreach ($sections as $key) {
            if (! $this->policy()->sectionAllowed($everything, $key, $live)) {
                throw new MatineeShareException();
            }
        }
    }

    /** An everyone-share of this server ($projectId null) or a share of this server with this project. */
    private function exists(int $serverId, ?int $projectId): bool
    {
        $query = $this->db->table(self::TABLE)->eq('server_id', $serverId);
        if ($projectId === null) {
            $query->eq('audience', SharePolicy::AUDIENCE_EVERYONE);
        } else {
            $query->eq('project_id', $projectId);
        }
        return $query->exists();
    }

    private function writeSections(int $shareId, array $sections): void
    {
        foreach ($sections as $key) {
            if (! $this->db->table(self::SECTIONS_TABLE)->insert(['share_id' => $shareId, 'section_key' => $key])) {
                throw new \RuntimeException('section insert failed');
            }
        }
    }

    /** @return list<array<string, mixed>> the rows, each with `sections` in natural order */
    private function withSections(array $shares): array
    {
        if ($shares === []) {
            return [];
        }
        $byShare = [];
        $rows = $this->db->table(self::SECTIONS_TABLE)
            ->columns('share_id', 'section_key')
            ->in('share_id', array_map(static fn (array $s): int => (int) $s['id'], $shares))
            ->findAll();
        foreach ($rows as $row) {
            $byShare[(int) $row['share_id']][] = (string) $row['section_key'];
        }
        foreach ($shares as &$share) {
            $keys = $byShare[(int) $share['id']] ?? [];
            sort($keys, SORT_NATURAL);
            $share['sections'] = $keys;
        }
        return $shares;
    }
}
