<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/support/ReleasesContainer.php';

use KanboardTests\units\Base;
use Kanboard\Core\Plugin\Hook;
use Kanboard\Plugin\Matinee\Plugin;
use PHPUnit\Framework\Attributes\DataProvider;

// Tag == plugin.json == getPluginVersion(): drift across the three is the most common release defect.
class PluginVersionTest extends Base
{
    use MatineeReleasesContainer;

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->releaseContainer();
    }

    public function testVersionsAgree(): void
    {
        $json = json_decode(file_get_contents(__DIR__.'/../plugin.json'), true);
        $plugin = new Plugin($this->container);
        $this->assertSame('0.1.0', $json['version']);
        $this->assertSame($json['version'], $plugin->getPluginVersion());
        $this->assertSame($json['name'], $plugin->getPluginName());
        $this->assertSame($json['description'], $plugin->getPluginDescription());
        $this->assertSame($json['author'], $plugin->getPluginAuthor());
        $this->assertSame($json['homepage'], $plugin->getPluginHomepage());
        $this->assertSame($json['kanboard_version'], $plugin->getCompatibleVersion());
        $this->assertSame('MIT', $json['license']);
        $this->assertSame('>=8.4', $json['php_version']);
        $this->assertSame([], $json['requires']);
        $this->assertSame([], $json['recommends']);
    }

    public static function extensionSets(): array
    {
        return [
            'both' => [['sodium', 'curl'], true],
            'sodium missing' => [['curl'], false],
            'curl missing' => [['sodium'], false],
            'neither' => [[], false],
        ];
    }

    /** @dataProvider extensionSets */
    #[DataProvider('extensionSets')]
    public function testIsSupportedNeedsSodiumAndCurl(array $loaded, bool $expected): void
    {
        $this->assertSame($expected, Plugin::isSupported(fn (string $ext) => in_array($ext, $loaded, true)));
    }

    // The unsupported path registers the admin notice and nothing else.
    public function testInitializeWhenUnsupportedRegistersOnlyTheNotice(): void
    {
        $this->assertSame(
            ['template:layout:top' => [['template' => 'Matinee:notice/unsupported']]],
            $this->hooksAfterInitialize(false)
        );
    }

    public function testInitializeWhenSupportedShowsNoNotice(): void
    {
        $this->assertArrayNotHasKey('template:layout:top', $this->hooksAfterInitialize(true));
    }

    private function hooksAfterInitialize(bool $supported): array
    {
        $this->container['hook'] = new Hook();
        $plugin = $supported
            ? new class($this->container) extends Plugin {
                public static function isSupported(?callable $loaded = null): bool { return true; }
            }
            : new class($this->container) extends Plugin {
                public static function isSupported(?callable $loaded = null): bool { return false; }
            };
        $plugin->initialize();
        return (fn () => $this->hooks)->call($this->container['hook']);
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
