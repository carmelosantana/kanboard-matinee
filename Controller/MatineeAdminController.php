<?php

namespace Kanboard\Plugin\Matinee\Controller;

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Matinee\Crypto\CryptoException;
use Kanboard\Plugin\Matinee\Crypto\KeyStoreException;
use Kanboard\Plugin\Matinee\Http\UrlGuard;
use Kanboard\Plugin\Matinee\Model\MatineeForbiddenException;
use Kanboard\Plugin\Matinee\Model\Settings;

/**
 * Settings → Matinee (spec §4 admin list, §11 S1): who may connect, private targets, allowed hosts and the two limits,
 * and every server with its owner, host, status and share count behind an enable/disable switch. No token, no edit.
 * ACL is APP_ADMIN; each action checks again.
 */
class MatineeAdminController extends MatineeBaseController
{
    /** The highest attempt limit or server cap the form accepts. */
    private const LIMIT_MAX = 1000;

    public function show(): void
    {
        $this->assertAdmin();
        $settings = new Settings($this->container);
        $me = $this->actorId();
        $owners = [];
        $rows = [];
        foreach ($this->servers()->listAll() as $server) {
            $ownerId = (int) $server['owner_user_id'];
            $owners[$ownerId] ??= (string) ($this->userModel->getById($ownerId)['username'] ?? '#'.$ownerId);
            $rows[] = [
                'id' => (int) $server['id'],
                'name' => (string) $server['name'],
                'owner' => $owners[$ownerId],
                'host' => self::host($server),
                'status' => $this->statusLabel($server),
                'is_enabled' => (int) $server['is_enabled'] === 1,
                'share_count' => count($this->shares()->listForServer((int) $server['id'], $me)),
            ];
        }

        $this->response->html($this->helper->layout->config('Matinee:admin/settings', [
            'title' => t('Settings').' &gt; Matinee',
            'values' => [
                Settings::CONNECT_POLICY => $settings->connectPolicy(),
                Settings::PRIVATE_TARGETS => (string) $this->configModel->getOption(Settings::PRIVATE_TARGETS),
                Settings::ALLOWED_HOSTS => (string) $this->configModel->getOption(Settings::ALLOWED_HOSTS),
                Settings::ATTEMPT_LIMIT_KEY => (string) $settings->attemptLimit(),
                Settings::MAX_SERVERS_KEY => (string) $settings->maxServers(),
            ],
            'policies' => [Settings::POLICY_EVERYONE => t('Every user'), Settings::POLICY_ADMINS => t('Administrators only')],
            'servers' => $rows,
            'key' => $this->keyStatus(),
        ]));
    }

    /**
     * Spec §2: where the root key comes from ("env" or "file") and its 8-hex id, or null when it cannot be loaded. The
     * page then shows one fixed notice: never the key, the env value, the path or the exception's message.
     *
     * @return array{source: string, id: string}|null
     */
    private function keyStatus(): ?array
    {
        try {
            $store = $this->container['matineeKeyStore'];
            return ['source' => $store->keyId(), 'id' => $store->activeKeyId()];
        } catch (KeyStoreException|CryptoException) {
            return null;
        }
    }

    /** Every field is checked before anything is saved; a bad list entry is named by its line number only. */
    public function save(): void
    {
        $this->assertAdmin();
        $this->checkPost();

        $policy = $this->field(Settings::CONNECT_POLICY);
        $targets = $this->field(Settings::PRIVATE_TARGETS);
        $hosts = $this->field(Settings::ALLOWED_HOSTS);
        $attempts = $this->field(Settings::ATTEMPT_LIMIT_KEY);
        $maxServers = $this->field(Settings::MAX_SERVERS_KEY);

        $error = match (true) {
            ! in_array($policy, [Settings::POLICY_EVERYONE, Settings::POLICY_ADMINS], true) => t('Choose who may connect servers.'),
            ($line = self::badLine($targets, [UrlGuard::class, 'isValidTargetEntry'])) !== null
                => t('Private targets: line %d is not a host, IP address or CIDR range with an optional port.', $line),
            ($line = self::badLine($hosts, [UrlGuard::class, 'isValidHostEntry'])) !== null
                => t('Allowed hosts: line %d is not a host name, IP address or *.domain.', $line),
            ! self::validLimit($attempts) => t('The attempt limit must be a whole number from 1 to %d.', self::LIMIT_MAX),
            ! self::validLimit($maxServers) => t('The server limit must be a whole number from 1 to %d.', self::LIMIT_MAX),
            default => null,
        };
        if ($error !== null) {
            $this->flash->failure($error);
            $this->redirectToShow();
            return;
        }

        $saved = $this->configModel->save([
            Settings::CONNECT_POLICY => $policy,
            Settings::PRIVATE_TARGETS => implode("\n", UrlGuard::parseTargetList($targets)),
            Settings::ALLOWED_HOSTS => implode("\n", UrlGuard::parseHostList($hosts)),
            Settings::ATTEMPT_LIMIT_KEY => (string) (int) $attempts,
            Settings::MAX_SERVERS_KEY => (string) (int) $maxServers,
        ]);
        if ($saved) {
            $this->flash->success(t('Settings saved successfully.'));
        } else {
            $this->flash->failure(t('Unable to save your settings.'));
        }
        $this->redirectToShow();
    }

    /** The kill switch: `enabled` is the state wanted (1 or 0), so a repeated submit changes nothing. */
    public function toggle(): void
    {
        $this->assertAdmin();
        $this->checkPost();
        $on = $this->field('enabled') === '1';
        try {
            $this->servers()->setEnabled($this->request->getIntegerParam('server_id'), $on, $this->actorId());
        } catch (MatineeForbiddenException) {
            throw new AccessForbiddenException();
        }
        $this->flash->success($on ? t('Server enabled.') : t('Server disabled.'));
        $this->redirectToShow();
    }

    /** The 1-based number of the first line holding an entry $valid refuses, or null. Entries split as the guard splits them. */
    private static function badLine(string $raw, callable $valid): ?int
    {
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $i => $line) {
            foreach (preg_split('/[\s,]+/', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
                if (! $valid($entry)) {
                    return $i + 1;
                }
            }
        }
        return null;
    }

    private static function validLimit(string $value): bool
    {
        return ctype_digit($value) && strlen($value) <= 4 && (int) $value >= 1 && (int) $value <= self::LIMIT_MAX;
    }

    private function assertAdmin(): void
    {
        if (! $this->userSession->isAdmin() || ! $this->policy()->isActiveAdmin($this->actorId())) {
            throw new AccessForbiddenException();
        }
    }

    private function redirectToShow(): void
    {
        $this->response->redirect($this->helper->url->to('MatineeAdminController', 'show', ['plugin' => 'Matinee']));
    }
}
