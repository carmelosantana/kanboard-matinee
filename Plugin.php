<?php
namespace Kanboard\Plugin\Matinee;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize(): void
    {
        // Without sodium (token sealing) or curl (pinned outbound requests) Matinee can't run safely:
        // register only the admin notice, no routes.
        if (! self::isSupported()) {
            $this->hook->on('template:layout:top', ['template' => 'Matinee:notice/unsupported']);
            return;
        }
    }

    public static function isSupported(): bool
    {
        return extension_loaded('sodium') && extension_loaded('curl');
    }

    public function getPluginName(): string        { return 'Matinee'; }
    public function getPluginDescription(): string { return t('Share your Plex server\'s libraries with Kanboard projects.'); }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '0.1.0'; }
    public function getPluginHomepage(): string    { return 'https://github.com/carmelosantana/kanboard-matinee'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }
}
