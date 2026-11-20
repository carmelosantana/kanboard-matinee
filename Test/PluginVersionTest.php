<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Matinee\Plugin;

// Tag == plugin.json == getPluginVersion(): drift across the three is the most common release defect.
class PluginVersionTest extends Base
{
    public function testVersionsAgree(): void
    {
        $json = json_decode(file_get_contents(__DIR__.'/../plugin.json'), true);
        $plugin = new Plugin($this->container);
        $this->assertSame('0.1.0', $json['version']);
        $this->assertSame($json['version'], $plugin->getPluginVersion());
        $this->assertSame($json['name'], $plugin->getPluginName());
        $this->assertSame($json['author'], $plugin->getPluginAuthor());
        $this->assertSame($json['homepage'], $plugin->getPluginHomepage());
        $this->assertSame($json['kanboard_version'], $plugin->getCompatibleVersion());
        $this->assertSame('MIT', $json['license']);
        $this->assertSame([], $json['requires']);
        $this->assertSame([], $json['recommends']);
    }

    public function testIsSupportedNeedsSodiumAndCurl(): void
    {
        $this->assertSame(extension_loaded('sodium') && extension_loaded('curl'), Plugin::isSupported());
    }

    public function testUnsupportedNoticeShowsToAdminsOnly(): void
    {
        $template = $this->container['template'];
        $_SESSION['user'] = ['id' => 1, 'role' => \Kanboard\Core\Security\Role::APP_ADMIN];
        $this->assertStringContainsString('Matinee is disabled', $template->render('Matinee:notice/unsupported'));
        $_SESSION['user'] = ['id' => 2, 'role' => \Kanboard\Core\Security\Role::APP_USER];
        $this->assertStringNotContainsString('Matinee is disabled', $template->render('Matinee:notice/unsupported'));
    }
}
