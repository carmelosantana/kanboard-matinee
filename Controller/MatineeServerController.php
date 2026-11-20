<?php

namespace Kanboard\Plugin\Matinee\Controller;

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Matinee\Http\UrlGuard;
use Kanboard\Plugin\Matinee\Model\MatineeConnectException;
use Kanboard\Plugin\Matinee\Model\MatineeForbiddenException;

/**
 * User settings → Plex servers (spec §4, §5). Any signed-in user reaches it (ACL APP_USER); every ability is then
 * decided from the loaded server row, by SharePolicy or the model, never by an id in the URL alone.
 *
 * The token is write-only: it is read from the POST once, handed to the model, and never put in a template, a flash
 * or a log line. A failed form re-renders the name and a valid base address only.
 */
class MatineeServerController extends MatineeBaseController
{
    public function index(): void
    {
        $me = $this->actorId();
        $rows = [];
        foreach ($this->servers()->listForOwner($me) as $server) {
            $rows[] = $this->row($server);
        }
        $this->renderUser('Matinee:user/servers', [
            'servers' => $rows,
            'can_connect' => $this->policy()->canConnectServer($me),
        ]);
    }

    public function create(): void
    {
        if (! $this->policy()->canConnectServer($this->actorId())) {
            throw new AccessForbiddenException();
        }
        $this->renderForm(null, ['name' => '', 'url' => '']);
    }

    public function edit(): void
    {
        $server = $this->ownedServer();
        $this->renderForm($server, ['name' => $server['name'], 'url' => $server['base_url']]);
    }

    /** Connect (no server_id) or update (the owner's server_id). A blank token keeps the stored one. */
    public function save(): void
    {
        $this->checkPost();
        $me = $this->actorId();
        $serverId = (int) $this->field('server_id');
        $name = $this->field('name');
        $url = $this->field('url');
        $token = $this->field('token');

        try {
            if ($serverId > 0) {
                $this->servers()->update($serverId, $me, $name, $url, $token === '' ? null : $token);
                $this->flash->success(t('Server saved.'));
            } else {
                $serverId = $this->servers()->connect($me, $name, $url, $token);
                $this->flash->success(t('Server connected.'));
            }
        } catch (MatineeForbiddenException) {
            throw new AccessForbiddenException();
        } catch (MatineeConnectException $e) {
            $this->flash->failure(self::connectMessage($e));
            // An address that is not a plain base URL may carry the token (a pasted ?X-Plex-Token= or user:token@):
            // it is not echoed back.
            $this->renderForm($serverId > 0 ? $this->servers()->get($serverId) : null, ['name' => $name, 'url' => UrlGuard::isValidBaseUrl($url) ? $url : '']);
            return;
        }
        $this->redirectToServer($serverId);
    }

    /** The owner's page, or an admin's (SharePolicy::canManageServer): status and actions. */
    public function show(): void
    {
        $me = $this->actorId();
        $server = $this->servers()->get($this->request->getIntegerParam('server_id'));
        if ($server === null || ! $this->policy()->canManageServer($me, $server)) {
            throw new AccessForbiddenException();
        }
        $this->renderUser('Matinee:user/server_show', [
            'server' => $this->row($server),
            'is_owner' => (int) $server['owner_user_id'] === $me,
        ]);
    }

    public function verify(): void
    {
        $this->checkPost();
        $serverId = $this->request->getIntegerParam('server_id');
        try {
            $this->servers()->verify($serverId, $this->actorId());
            $this->flash->success(t('Server is reachable.'));
        } catch (MatineeForbiddenException) {
            throw new AccessForbiddenException();
        } catch (MatineeConnectException $e) {
            $this->flash->failure(self::connectMessage($e));
        }
        $this->redirectToServer($serverId);
    }

    public function remove(): void
    {
        $this->checkPost();
        try {
            $this->servers()->remove($this->request->getIntegerParam('server_id'), $this->actorId());
        } catch (MatineeForbiddenException) {
            throw new AccessForbiddenException();
        }
        $this->flash->success(t('Server removed.'));
        $this->response->redirect($this->helper->url->to('MatineeServerController', 'index', ['plugin' => 'Matinee']));
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** The server in the URL, if the signed-in user owns it; anything else is 403 (missing reads the same). */
    private function ownedServer(): array
    {
        $server = $this->servers()->get($this->request->getIntegerParam('server_id'));
        if ($server === null || (int) $server['owner_user_id'] !== $this->actorId()) {
            throw new AccessForbiddenException();
        }
        return $server;
    }

    /** The public columns plus what the pages show: the host only, never the address, and a status label. */
    private function row(array $server): array
    {
        return [
            'id' => (int) $server['id'],
            'name' => (string) $server['name'],
            'host' => self::host($server),
            'status' => $this->statusLabel($server),
            'is_enabled' => (int) $server['is_enabled'] === 1,
            'last_checked_at' => (int) $server['last_checked_at'],
        ];
    }

    /** @param array{name: string, url: string} $values never the token */
    private function renderForm(?array $server, array $values): void
    {
        $this->renderUser('Matinee:user/server_form', [
            'server' => $server === null ? null : $this->row($server),
            'values' => ['name' => $values['name'], 'url' => $values['url']],
        ]);
    }

    private function renderUser(string $template, array $params): void
    {
        $this->response->html($this->helper->layout->user($template, $params + [
            'user' => $this->userModel->getById($this->actorId()),
        ]));
    }

    private function redirectToServer(int $serverId): void
    {
        $this->response->redirect($this->helper->url->to('MatineeServerController', 'show', ['plugin' => 'Matinee', 'server_id' => $serverId]));
    }
}
