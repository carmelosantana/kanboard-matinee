<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Matinee\Plugin;

/** Matinee's clean URLs (plan Task 9), booted as in production: core's routes first, then the plugin's. */
class RoutesTest extends Base
{
    /** concrete path => [controller, action, route params] */
    private const ROUTES = [
        'matinee/servers' => ['MatineeServerController', 'index', []],
        'matinee/servers/new' => ['MatineeServerController', 'create', []],
        'matinee/servers/save' => ['MatineeServerController', 'save', []],
        'matinee/servers/7' => ['MatineeServerController', 'show', ['server_id' => '7']],
        'matinee/servers/7/edit' => ['MatineeServerController', 'edit', ['server_id' => '7']],
        'matinee/servers/7/verify' => ['MatineeServerController', 'verify', ['server_id' => '7']],
        'matinee/servers/7/remove' => ['MatineeServerController', 'remove', ['server_id' => '7']],
        'matinee/servers/7/share' => ['MatineeServerController', 'share', ['server_id' => '7']],
        'matinee/admin' => ['MatineeAdminController', 'show', []],
        'matinee/admin/save' => ['MatineeAdminController', 'save', []],
        'matinee/admin/servers/7/toggle' => ['MatineeAdminController', 'toggle', ['server_id' => '7']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->container['route']->enable();
        preg_match_all("/->addRoute\\('([^']+)', '([^']+)', '([^']+)'\\)/", file_get_contents(ROOT_DIR.'/app/ServiceProvider/RouteProvider.php'), $m, PREG_SET_ORDER);
        foreach ($m as [, $path, $controller, $action]) {
            $this->container['route']->addRoute($path, $controller, $action);
        }
        (new Plugin($this->container))->initialize();
    }

    public function testEveryPathReachesItsActionAndEveryActionHasOnePath(): void
    {
        $route = $this->container['route'];
        foreach (self::ROUTES as $path => [$controller, $action, $params]) {
            $this->assertSame(['controller' => $controller, 'action' => $action, 'plugin' => 'Matinee'], $route->findRoute($path), $path);
            // href() builds the same path back from the route params.
            $this->assertSame($path, $route->findUrl($controller, $action, $params + ['plugin' => 'Matinee']), $path);
        }
        $listed = array_map(fn ($row) => $row[0].'::'.$row[1], array_values(self::ROUTES));
        $registered = array_map(fn ($row) => $row[1].'::'.$row[2], Plugin::ROUTES);
        sort($listed);
        sort($registered);
        $this->assertSame($listed, $registered);
    }
}
