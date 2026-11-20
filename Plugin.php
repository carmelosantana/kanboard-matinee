<?php
namespace Kanboard\Plugin\Matinee;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\Matinee\Crypto\KeyStore;
use Kanboard\Plugin\Matinee\Http\CurlTransport;
use Kanboard\Plugin\Matinee\Http\PlexClient;
use Kanboard\Plugin\Matinee\Http\UrlGuard;
use Kanboard\Plugin\Matinee\Model\ServerModel;
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
        // Builds a Plex client over the guarded, pinned CurlTransport. Tests swap in FakeTransport.
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
