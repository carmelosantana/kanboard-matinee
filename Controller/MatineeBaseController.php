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
 * refusal carries it too (Plugin::sendReferrerPolicy covers the refusals core makes before a controller exists);
 * POST-and-CSRF for every state change; and the fixed, translated text for model failures.
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

    protected function servers(): ServerModel
    {
        return $this->container['matineeServerModel'];
    }

    protected function policy(): MatineePolicy
    {
        return $this->container['matineePolicy'];
    }
}
