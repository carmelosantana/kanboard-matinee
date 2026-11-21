<?php

namespace Kanboard\Plugin\Matinee\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Matinee\Model\MatineeConnectException;
use Kanboard\Plugin\Matinee\Model\ServerModel;
use Kanboard\Plugin\Matinee\Model\MatineePolicy;
use Kanboard\Plugin\Matinee\Plugin;
use Pimple\Container;

/**
 * What every Matinee page shares: `Referrer-Policy: same-origin` on every response (§11 S9), set before any check so a
 * refusal carries it too (Plugin::sendReferrerPolicy covers the refusals core makes before a controller exists); the
 * lazy sweep's dice (C4d); POST-and-CSRF for every state change; and the fixed, translated text for model failures.
 * Nothing a model throws is shown: its message is a key, mapped here to one fixed sentence.
 */
abstract class MatineeBaseController extends BaseController
{
    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->response->withHeader('Referrer-Policy', 'same-origin');
        // Without sodium or curl the plugin registers no routes, but ?controller=…&plugin=Matinee still reaches here.
        if (! static::supported()) {
            throw new AccessForbiddenException();
        }
        $this->maybeSweep();
    }

    /** The frequent, latency-sensitive actions the sweep never delays (C4d), lowercased. */
    public const SWEEP_SKIPS = ['media', 'hlsstart', 'hlsproxy', 'hlsping', 'keepalive'];

    /** Logged when a sweep fails: the exception's short class name only, never its message. */
    public const SWEEP_FAILED_LOG = 'Matinee: sweep failed (%s)';

    /**
     * The lazy sweep (C4d): rolls PlaybackModel::maybeSweep()'s dice for a signed-in user, unless the action is one of
     * SWEEP_SKIPS. Core builds a controller only after every middleware (authentication, 2FA, the ACL) has passed and
     * the class exists, so nothing else can roll. The router keeps the action as typed and PHP dispatches it in any
     * case, so it is compared lowercased. A failure is logged and swallowed: a sweep never breaks a page.
     */
    private function maybeSweep(): void
    {
        if (! $this->userSession->isLogged() || in_array(strtolower($this->router->getAction()), self::SWEEP_SKIPS, true)) {
            return;
        }
        try {
            $this->container['matineePlaybackModel']->maybeSweep();
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(self::SWEEP_FAILED_LOG, (new \ReflectionClass($e))->getShortName()));
        }
    }

    /** Plugin::isSupported(); tests override it, as they do Plugin's. */
    protected static function supported(): bool
    {
        return Plugin::isSupported();
    }

    /** A POST with this form's single-use CSRF token, or 403. Read the fields with getRawValue() afterwards. */
    protected function checkPost(): void
    {
        if (! $this->request->isPost()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();
    }

    protected function actorId(): int
    {
        return (int) $this->userSession->getId();
    }

    /** A query param as a string; an array or nothing is ''. */
    protected function param(string $name): string
    {
        $value = $this->request->getStringParam($name);
        return is_string($value) ? $value : '';
    }

    /** A posted field as a string; anything else (an array, nothing) is ''. */
    protected function field(string $name): string
    {
        $value = $this->request->getRawValue($name);
        return is_scalar($value) ? (string) $value : '';
    }

    protected static function connectMessage(MatineeConnectException $e): string
    {
        return $e->getMessage() === MatineeConnectException::TOKEN_REQUIRED
            ? t('Enter the token again to change the server address.')
            : t('Could not connect to that server with that token. Check the address includes :32400 and the token is current.');
    }

    protected function statusLabel(array $server): string
    {
        if ((int) $server['is_enabled'] !== 1) {
            return t('Disabled by an admin');
        }
        return match ($server['status']) {
            ServerModel::STATUS_OK => t('Connected'),
            ServerModel::STATUS_RECONNECT => t('Needs the token again'),
            default => t('Unreachable'),
        };
    }

    /** The host of a stored base URL: pages never show the full address. */
    protected static function host(array $server): string
    {
        return (string) parse_url((string) $server['base_url'], PHP_URL_HOST);
    }

    /** A page in the dashboard layout, titled Media: the browse pages and the player. */
    protected function renderMedia(string $template, array $params, int $status = 200): void
    {
        $this->response->html($this->helper->layout->dashboard($template, $params + [
            'user' => $this->userModel->getById($this->actorId()),
            'title' => t('Media'),
        ]), $status);
    }

    protected function servers(): ServerModel
    {
        return $this->container['matineeServerModel'];
    }

    protected function policy(): MatineePolicy
    {
        return $this->container['matineePolicy'];
    }
}
