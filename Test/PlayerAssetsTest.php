<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/support/ControllerHarness.php';

use KanboardTests\units\Base;
use Kanboard\Middleware\BootstrapMiddleware;
use Kanboard\Plugin\Matinee\Plugin;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * The player's CSP and assets (plan P3 Task 17; spec S10; C6a): only `matinee/play` widens the CSP with media-src and
 * worker-src (`'self' blob:`) and loads hls.js then player.js. script-src is never written.
 */
class PlayerAssetsTest extends Base
{
    private const HLS = 'plugins/Matinee/Assets/vendor/hls.js/hls.min.js';
    private const PLAYER = 'plugins/Matinee/Assets/js/player.js';
    /** Core's default rules (ClassProvider), as a test run starts with them. */
    private const CORE = [
        'default-src' => "'self'",
        'style-src' => "'self' 'unsafe-inline'",
        'img-src' => '* data:',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        (new Plugin($this->container))->initialize();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->dispatcher = null;
        gc_collect_cycles();
    }

    /** Routes a GET to $controller::$action as the router would, then fires app.bootstrap. */
    private function bootstrap(string $controller, string $action, string $plugin = 'Matinee'): MatineeTestResponse
    {
        unset($this->container['request'], $this->container['response']);
        $this->container['request'] = new \Kanboard\Core\Http\Request($this->container, ['REQUEST_METHOD' => 'GET'],
            ['controller' => $controller, 'action' => $action, 'plugin' => $plugin], [], [], []);
        $this->container['response'] = $response = new MatineeTestResponse($this->container);
        $this->container['router']->dispatch();
        $this->container['dispatcher']->dispatch(new Event(), 'app.bootstrap');
        return $response;
    }

    /** @return list<string> the js files hooked onto the layout, in order */
    private function js(): array
    {
        return array_map(static fn (array $l): string => $l['template'], $this->container['hook']->getListeners('template:layout:js'));
    }

    public function testThePlayRouteAddsMediaAndWorkerSrcAndNothingElse(): void
    {
        $this->assertSame(self::CORE, $this->container['cspRules']);
        $this->bootstrap('MatineePlayController', 'play');
        $this->assertSame(self::CORE + ['media-src' => "'self' blob:", 'worker-src' => "'self' blob:"], $this->container['cspRules']);
        $this->assertArrayNotHasKey('script-src', $this->container['cspRules']);
    }

    public function testAPriorScriptSrcIsLeftAsItIs(): void
    {
        $seeded = self::CORE + ['script-src' => "'self'"];
        $this->container['cspRules'] = $seeded;
        $this->bootstrap('MatineePlayController', 'play');
        $this->assertSame("'self'", $this->container['cspRules']['script-src']);
        $this->assertSame($seeded + ['media-src' => "'self' blob:", 'worker-src' => "'self' blob:"], $this->container['cspRules']);
    }

    /** C6a: the listener runs before BootstrapMiddleware copies the rules into the response. */
    public function testTheSentHeaderCarriesBothDirectives(): void
    {
        $response = $this->bootstrap('MatineePlayController', 'play');
        (new \ReflectionMethod(BootstrapMiddleware::class, 'sendHeaders'))->invoke(new BootstrapMiddleware($this->container));
        $csp = $response->headers()['Content-Security-Policy'] ?? '';
        $this->assertStringContainsString("media-src 'self' blob:;", $csp);
        $this->assertStringContainsString("worker-src 'self' blob:;", $csp);
        $this->assertStringNotContainsString('script-src', $csp);
    }

    public function testThePlayRouteLoadsHlsJsThenThePlayer(): void
    {
        $this->bootstrap('MatineePlayController', 'play');
        $this->assertSame([self::HLS, self::PLAYER], $this->js());
    }

    public function testEveryOtherRouteIsUntouched(): void
    {
        foreach ([
            ['MatineeBrowseController', 'listing', 'Matinee'],
            ['MatineeBrowseController', 'index', 'Matinee'],
            ['MatineePlayController', 'media', 'Matinee'],
            ['MatineePlayController', 'hlsStart', 'Matinee'],
            ['DashboardController', 'show', ''],
        ] as [$controller, $action, $plugin]) {
            $this->bootstrap($controller, $action, $plugin);
            $this->assertSame(self::CORE, $this->container['cspRules'], "$controller::$action");
            $this->assertSame([], $this->js(), "$controller::$action");
        }
    }

    // ── player.js (Task 19; C2g, C6b, C6c): static checks, the plugin being buildless ─────────────────

    private static function playerJs(): string
    {
        $file = __DIR__.'/../Assets/js/player.js';
        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    public function testBothRegisteredAssetsExist(): void
    {
        foreach (Plugin::PLAYER_JS as $file) {
            // As AssetHelper::js() resolves it, from Kanboard's root.
            $this->assertFileExists(ROOT_DIR.'/'.$file);
        }
    }

    public function testThePlayerUsesTheBeaconThePingTheKeepaliveAndTheBfcacheReload(): void
    {
        $js = self::playerJs();
        foreach (['sendBeacon', "'pagehide'", "'pageshow'", 'event.persisted', 'location.reload()', 'csrf_token',
            'setInterval', 'clearInterval', 'Hls.isSupported()', "canPlayType('application/vnd.apple.mpegurl')",
            'Hls.Events.ERROR', 'data.fatal', "credentials: 'same-origin'", 'response.status === 403', "attr('keepalive')",
            'textContent'] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
        $this->assertMatchesRegularExpression('#^\(function \(\) \{#m', $js, 'an IIFE');
    }

    public function testHlsJsIsTriedBeforeNativeHls(): void
    {
        $js = self::playerJs();
        $hls = strpos($js, 'Hls.isSupported()');
        $native = strpos($js, "canPlayType('application/vnd.apple.mpegurl')");
        $this->assertIsInt($hls);
        $this->assertIsInt($native);
        $this->assertLessThan($native, $hls);
    }

    public function testThePingIsEvery30SecondsAndTheKeepaliveEvery5Minutes(): void
    {
        $js = self::playerJs();
        $this->assertSame(2, preg_match_all('#setInterval\(#', $js));
        $this->assertMatchesRegularExpression("#setInterval\(function \(\) \{\s*post\(attr\('ping'\)[\s\S]*?\}, 30000\);#", $js);
        $this->assertMatchesRegularExpression("#setInterval\(function \(\) \{\s*post\(attr\('keepalive'\)[\s\S]*?\}, 300000\);#", $js);
    }

    public function testThePlayerNeverEvaluatesOrInjectsMarkup(): void
    {
        $js = self::playerJs();
        $this->assertNotSame('', $js);
        foreach (['eval(', 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'new Function'] as $bad) {
            $this->assertStringNotContainsString($bad, $js, $bad);
        }
    }
}
