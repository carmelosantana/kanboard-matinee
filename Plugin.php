<?php
namespace Kanboard\Plugin\Matinee;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Matinee\Crypto\KeyStore;
use Kanboard\Plugin\Matinee\Http\CurlTransport;
use Kanboard\Plugin\Matinee\Http\PlexClient;
use Kanboard\Plugin\Matinee\Http\UrlGuard;
use Kanboard\Plugin\Matinee\Model\ServerModel;
use Kanboard\Plugin\Matinee\Model\ShareModel;
use Kanboard\Plugin\Matinee\Model\SharePolicy;
use Kanboard\Plugin\Matinee\Model\Settings;

class Plugin extends Base
{
    public function initialize(): void
    {
        // Without sodium (token sealing) or curl (pinned outbound requests) Matinee can't run safely:
        // register only the admin notice, no routes.
        if (! static::isSupported()) {
            $this->hook->on('template:layout:top', ['template' => 'Matinee:notice/unsupported']);
            return;
        }

        $container = $this->container;
        // The root key: MATINEE_KEY when set, else DATA_DIR/matinee/secret.key (spec §2). Tests swap in a temp dir.
        $container['matineeKeyStore'] = static fn (): KeyStore => new KeyStore(DATA_DIR.'/matinee', getenv('MATINEE_KEY') ?: null);
        // Builds a Plex client over the guarded, pinned CurlTransport. Tests swap in FakeTransport. It only reads the
        // client id (ServerModel ensures it first), so building a client never writes.
        $container['matineePlexClientFactory'] = $container->protect(
            static function (string $baseUrl, #[\SensitiveParameter] string $token, ?string $machineId, string $deviceName) use ($container): PlexClient {
                return new PlexClient(
                    $baseUrl,
                    $token,
                    (new Settings($container))->clientId(),
                    new CurlTransport(UrlGuard::fromConfig($container['configModel'])),
                    $machineId,
                    $deviceName
                );
            }
        );
        $container['matineeServerModel'] = static fn ($c): ServerModel => new ServerModel($c);
        // The policy's lookups read the DB on every call; only the closures are shared.
        $container['matineeSharePolicy'] = static fn ($c): SharePolicy => SharePolicy::fromContainer($c);
        $container['matineeShareModel'] = static fn ($c): ShareModel => new ShareModel($c);

        // ACL: any signed-in user reaches the server pages (each action then checks the loaded row); the admin pages
        // are admins only (each action checks again). Anonymous users reach neither.
        foreach (self::ACL as [$controller, $actions, $role]) {
            $this->applicationAccessMap->add($controller, $actions, $role);
        }
        foreach (self::ROUTES as [$path, $controller, $action]) {
            $this->route->addRoute($path, $controller, $action, 'Matinee');
        }
        // Referrer-Policy on every request routed to Matinee, set on app.bootstrap (the router has dispatched by then)
        // so a refusal core's ACL middleware makes before any Matinee controller is built carries it too (§11 S9).
        $this->dispatcher->addListener('app.bootstrap', [$this, 'sendReferrerPolicy']);
        $this->hook->on('template:user:sidebar:actions', ['template' => 'Matinee:user/sidebar']);
        $this->hook->on('template:config:sidebar', ['template' => 'Matinee:config/sidebar']);
    }

    /** [controller, actions, lowest application role]: every Matinee action. */
    public const ACL = [
        ['MatineeServerController', ['index', 'create', 'edit', 'save', 'show', 'verify', 'remove', 'shareCreate', 'shareSave', 'shareUnlink'], Role::APP_USER],
        ['MatineeAdminController', ['show', 'save', 'toggle'], Role::APP_ADMIN],
    ];

    /** [path, controller, action]. Fixed paths come before `:server_id` ones: the first match wins. */
    public const ROUTES = [
        ['matinee/servers', 'MatineeServerController', 'index'],
        ['matinee/servers/new', 'MatineeServerController', 'create'],
        ['matinee/servers/save', 'MatineeServerController', 'save'],
        ['matinee/servers/:server_id', 'MatineeServerController', 'show'],
        ['matinee/servers/:server_id/edit', 'MatineeServerController', 'edit'],
        ['matinee/servers/:server_id/verify', 'MatineeServerController', 'verify'],
        ['matinee/servers/:server_id/remove', 'MatineeServerController', 'remove'],
        ['matinee/servers/:server_id/shares/new', 'MatineeServerController', 'shareCreate'],
        ['matinee/servers/:server_id/shares/save', 'MatineeServerController', 'shareSave'],
        ['matinee/shares/:share_id/unlink', 'MatineeServerController', 'shareUnlink'],
        ['matinee/admin', 'MatineeAdminController', 'show'],
        ['matinee/admin/save', 'MatineeAdminController', 'save'],
        ['matinee/admin/servers/:server_id/toggle', 'MatineeAdminController', 'toggle'],
    ];

    /** On app.bootstrap: `Referrer-Policy: same-origin` when the routed plugin is Matinee. */
    public function sendReferrerPolicy(): void
    {
        if ($this->router->getPlugin() === 'Matinee') {
            $this->response->withHeader('Referrer-Policy', 'same-origin');
        }
    }

    /** $loaded defaults to extension_loaded; tests pass a stand-in. */
    public static function isSupported(?callable $loaded = null): bool
    {
        $loaded ??= 'extension_loaded';
        return $loaded('sodium') && $loaded('curl');
    }

    public function getPluginName(): string        { return 'Matinee'; }
    public function getPluginDescription(): string { return t('Share Plex libraries with Kanboard projects.'); }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '0.1.0'; }
    public function getPluginHomepage(): string    { return 'https://github.com/carmelosantana/kanboard-matinee'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }
}
