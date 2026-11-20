<?php
namespace Kanboard\Plugin\Matinee;

use Kanboard\Core\Plugin\Base;

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
