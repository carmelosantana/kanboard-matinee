<?php
namespace Kanboard\Plugin\Matinee;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Matinee\Crypto\KeyStore;
use Kanboard\Plugin\Matinee\Crypto\RefSigner;
use Kanboard\Plugin\Matinee\Crypto\Subkeys;
use Kanboard\Plugin\Matinee\Http\CurlTransport;
use Kanboard\Plugin\Matinee\Http\PlexClient;
use Kanboard\Plugin\Matinee\Http\PlexTransport;
use Kanboard\Plugin\Matinee\Http\ResponseSink;
use Kanboard\Plugin\Matinee\Http\UrlGuard;
use Kanboard\Plugin\Matinee\Model\BrowseModel;
use Kanboard\Plugin\Matinee\Model\MatineePolicy;
use Kanboard\Plugin\Matinee\Model\PlaybackModel;
use Kanboard\Plugin\Matinee\Model\ServerModel;
use Kanboard\Plugin\Matinee\Model\Settings;
use Kanboard\Plugin\Matinee\Model\TranscodeModel;

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
        // Signs browse and art refs with the refs subkey of the active root key, read when first used (spec §6).
        $container['matineeRefSigner'] = static fn ($c): RefSigner => new RefSigner(Subkeys::refs($c['matineeKeyStore']->rootKey()));
        // The guarded, pinned CurlTransport, built afresh on each use so the guard reads the current settings. It also
        // discovers a typed IP's plex.direct name (ServerModel). Tests swap in FakeTransport.
        $container['matineeTransport'] = $container->factory(
            static fn ($c): PlexTransport => new CurlTransport(UrlGuard::fromConfig($c['configModel']))
        );
        // Builds a Plex client over that transport. It only reads the client id (ServerModel ensures it first), so
        // building a client never writes.
        $container['matineePlexClientFactory'] = $container->protect(
            static function (string $baseUrl, #[\SensitiveParameter] string $token, ?string $machineId, string $deviceName) use ($container): PlexClient {
                return new PlexClient(
                    $baseUrl,
                    $token,
                    (new Settings($container))->clientId(),
                    $container['matineeTransport'],
                    $machineId,
                    $deviceName
                );
            }
        );
        $container['matineeServerModel'] = static fn ($c): ServerModel => new ServerModel($c);
        // The policy's lookups read the DB on every call; only the closures are shared.
        $container['matineePolicy'] = static fn ($c): MatineePolicy => MatineePolicy::fromContainer($c);
        // Turns a verified ref into a listing or an image, re-authorizing on every use (spec §6).
        $container['matineeBrowseModel'] = static fn ($c): BrowseModel => new BrowseModel($c);
        // Long-stream rows and the caps (P3 C4): DB only.
        $container['matineeTranscodeModel'] = static fn ($c): TranscodeModel => new TranscodeModel($c);
        // Turns a verified media ref into a player page or a stream, re-authorizing on every use (spec §7).
        $container['matineePlaybackModel'] = static fn ($c): PlaybackModel => new PlaybackModel($c);
        // Seams for PlaybackModel: the clock, and the registrar of the safety net that stops a claimed row if the
        // request dies mid-stream (C3f). Tests swap in a fixed clock and a recorder.
        $container['matineeClock'] = $container->protect(time(...));
        $container['matineeShutdown'] = $container->protect(register_shutdown_function(...));
        // The lazy sweep's dice (C4d): true on one request in ten. Tests stub it.
        $container['matineeSweepDice'] = $container->protect(static fn (): bool => random_int(1, 10) === 1);
        // The sink a stream endpoint writes the browser's response through (C3e); tests wire one to recorders.
        $container['matineeResponseSink'] = $container->factory(static fn (): ResponseSink => new ResponseSink());

        // ACL: any signed-in user reaches the server and browse pages (each action then checks the loaded row or the
        // signed ref); the admin pages are admins only (each action checks again). Anonymous users reach neither.
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
        // Browse is reached from the dashboard (ruling B5); its pages are styled by one stylesheet, no inline CSS.
        $this->hook->on('template:dashboard:sidebar', ['template' => 'Matinee:dashboard/sidebar']);
        $this->hook->on('template:layout:css', ['template' => 'plugins/Matinee/Assets/css/matinee.css']);
    }

    /** [controller, actions, lowest application role]: every Matinee action. */
    public const ACL = [
        ['MatineeServerController', ['index', 'create', 'edit', 'save', 'show', 'verify', 'remove', 'share'], Role::APP_USER],
        ['MatineeBrowseController', ['index', 'server', 'listing', 'art'], Role::APP_USER],
        ['MatineePlayController', ['keepalive', 'media'], Role::APP_USER],
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
        ['matinee/servers/:server_id/share', 'MatineeServerController', 'share'],
        ['matinee/browse', 'MatineeBrowseController', 'index'],
        ['matinee/browse/list', 'MatineeBrowseController', 'listing'],
        ['matinee/browse/:server_id', 'MatineeBrowseController', 'server'],
        ['matinee/art', 'MatineeBrowseController', 'art'],
        ['matinee/media', 'MatineePlayController', 'media'],
        ['matinee/keepalive', 'MatineePlayController', 'keepalive'],
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
    public function getPluginDescription(): string { return t('Share a Plex server with everyone on your Kanboard.'); }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '0.1.0'; }
    public function getPluginHomepage(): string    { return 'https://github.com/carmelosantana/kanboard-matinee'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }
}
