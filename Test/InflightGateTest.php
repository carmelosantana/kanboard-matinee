<?php

use Kanboard\Plugin\Matinee\Model\InflightGate;
use Kanboard\Plugin\Matinee\Model\MatineeBusyException;
use PHPUnit\Framework\TestCase;

/**
 * InflightGate (M1): real flocks on a temp dir. Slots are held by nesting run() calls, or by the test opening and
 * locking a slot file itself (another descriptor in this same process, which flock counts).
 */
class InflightGateTest extends TestCase
{
    private string $dir;
    private int $global = 8;

    /** @var list<resource> slot files the test holds */
    private array $held = [];

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/matinee-inflight-' . bin2hex(random_bytes(6)) . '/inflight';
    }

    protected function tearDown(): void
    {
        foreach ($this->held as $fh) {
            fclose($fh);
        }
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @chmod($this->dir, 0700);
        @rmdir($this->dir);
        @rmdir(dirname($this->dir));
    }

    /** @var list<int> the ms of each wait step the gate slept, through the injected sleeper */
    private array $slept = [];

    /** @var array<int, string> slot files the sleeper releases on its Nth call (1-based) */
    private array $freeOnSleep = [];

    private function gate(): InflightGate
    {
        return new InflightGate($this->dir, fn (): int => $this->global, InflightGate::PER_USER, function (string $line): void {
            $this->logged[] = $line;
        }, sleep: function (int $ms): void {
            $this->slept[] = $ms;
            if (count($this->slept) > 1000) {
                throw new \LogicException('the wait is unbounded');
            }
            if (isset($this->freeOnSleep[count($this->slept)])) {
                $this->release($this->freeOnSleep[count($this->slept)]);
            }
        });
    }

    /** Unlocks and closes the slot file $name the test holds. */
    private function release(string $name): void
    {
        $this->held = array_values(array_filter($this->held, function ($fh) use ($name): bool {
            if (stream_get_meta_data($fh)['uri'] !== $this->dir . '/' . $name) {
                return true;
            }
            flock($fh, LOCK_UN);
            fclose($fh);
            return false;
        }));
    }

    /** Runs $inner while holding $n slots of $userId through nested run() calls. */
    private function nest(InflightGate $gate, int $userId, int $n, \Closure $inner): mixed
    {
        return $n === 0 ? $inner() : $gate->run($userId, fn () => $this->nest($gate, $userId, $n - 1, $inner));
    }

    /** Locks the slot file $name through a descriptor of the test's own. */
    private function hold(string $name): void
    {
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0700, true);
        }
        $fh = fopen($this->dir . '/' . $name, 'c');
        $this->assertTrue(flock($fh, LOCK_EX | LOCK_NB), $name);
        $this->held[] = $fh;
    }

    private function assertBusy(\Closure $run): void
    {
        try {
            $run();
            $this->fail('expected MatineeBusyException');
        } catch (MatineeBusyException $e) {
            $this->assertSame(5, $e->retryAfter);
            $this->assertSame('matinee.busy', $e->getMessage());
        }
    }

    public function testRunReturnsWhatTheCallReturns(): void
    {
        $this->assertSame('ok', $this->gate()->run(7, fn () => 'ok'));
    }

    public function testFourPerUserSucceedAndTheFifthIsBusy(): void
    {
        $this->assertSame(4, InflightGate::PER_USER);
        $gate = $this->gate();
        $ran = false;
        $this->nest($gate, 7, 4, function () use ($gate, &$ran): void {
            $this->assertBusy(fn () => $gate->run(7, function () use (&$ran): void {
                $ran = true;
            }));
        });
        $this->assertFalse($ran, 'the call never runs when busy');
        $this->assertSame([], $this->logged);
    }

    public function testAnotherUserIsUnaffectedByOneUsersFullSlots(): void
    {
        $gate = $this->gate();
        $this->nest($gate, 7, 4, function () use ($gate): void {
            $this->assertSame('bob', $gate->run(8, fn () => 'bob'));
        });
    }

    public function testTheGlobalCapAppliesAcrossUsers(): void
    {
        $this->global = 3;
        $gate = $this->gate();
        $this->nest($gate, 1, 2, function () use ($gate): void {
            $gate->run(2, function () use ($gate): void {
                $this->assertBusy(fn () => $gate->run(3, fn () => null));
            });
        });
    }

    public function testTheGlobalCapIsReadOnEveryRun(): void
    {
        $this->global = 1;
        $gate = $this->gate();
        $gate->run(1, function () use ($gate): void {
            $this->assertBusy(fn () => $gate->run(2, fn () => null));
            $this->global = 2;
            $this->assertSame('ok', $gate->run(2, fn () => 'ok'));
        });
    }

    public function testReleaseFreesTheSlotAfterReturnAndAfterAThrow(): void
    {
        $gate = $this->gate();
        $this->global = 1;
        $gate->run(1, fn () => null);
        try {
            $gate->run(1, fn () => throw new \DomainException('x'));
            $this->fail('expected the call\'s exception');
        } catch (\DomainException) {
        }
        // Both slot files are free again: the test can lock them itself.
        $this->hold('u1-0.lock');
        $this->hold('g-0.lock');
    }

    /** flock is per open file description: a slot this process holds through another descriptor counts. */
    public function testASlotHeldThroughAnotherDescriptorOfThisProcessCounts(): void
    {
        foreach (range(0, 3) as $k) {
            $this->hold("u7-$k.lock");
        }
        $this->assertBusy(fn () => $this->gate()->run(7, fn () => null));
        $this->assertSame('other', $this->gate()->run(8, fn () => 'other'));
    }

    public function testAFullGlobalCapReleasesTheUserSlotItTook(): void
    {
        $this->global = 2;
        $this->hold('g-0.lock');
        $this->hold('g-1.lock');
        $this->assertBusy(fn () => $this->gate()->run(7, fn () => null));
        $this->hold('u7-0.lock');
    }

    public function testAFullUserTakesNoGlobalSlot(): void
    {
        $this->global = 1;
        foreach (range(0, 3) as $k) {
            $this->hold("u7-$k.lock");
        }
        $this->assertBusy(fn () => $this->gate()->run(7, fn () => null));
        $this->hold('g-0.lock');
    }

    public function testTheDirectoryIsCreated0700(): void
    {
        $this->gate()->run(1, fn () => null);
        $this->assertSame(0700, fileperms($this->dir) & 0777);
        $this->assertFileExists($this->dir . '/u1-0.lock');
        $this->assertFileExists($this->dir . '/g-0.lock');
    }

    public function testAnUnopenableSlotFileFailsClosedWithOneFixedLine(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores directory permissions');
        }
        mkdir($this->dir, 0700, true);
        chmod($this->dir, 0500);
        $this->assertBusy(fn () => $this->gate()->run(1, fn () => null));
        $this->assertSame([InflightGate::UNAVAILABLE_LOG], $this->logged);
    }

    public function testTheWaitDefaultsToTwoSecondsInFiftyMillisecondSteps(): void
    {
        $this->assertSame(2000, InflightGate::WAIT_MS);
        $this->assertSame(50, InflightGate::STEP_MS);
        $gate = new InflightGate($this->dir, fn (): int => 8);
        $prop = fn (string $name): mixed => (new \ReflectionProperty(InflightGate::class, $name))->getValue($gate);
        $this->assertSame(2000, $prop('waitMs'));
        $this->assertSame(50, $prop('stepMs'));
    }

    public function testAFreeSlotIsTakenWithoutWaiting(): void
    {
        $this->assertSame('ok', $this->gate()->run(7, fn () => 'ok'));
        $this->assertSame([], $this->slept);
    }

    public function testAUserSlotFreedDuringTheWaitIsTaken(): void
    {
        foreach (range(0, 3) as $k) {
            $this->hold("u7-$k.lock");
        }
        $this->freeOnSleep[3] = 'u7-2.lock';
        $this->assertSame('ok', $this->gate()->run(7, fn () => 'ok'));
        $this->assertSame([50, 50, 50], $this->slept, 'polled every step until the slot came free');
    }

    public function testAGlobalSlotFreedDuringTheWaitIsTaken(): void
    {
        $this->global = 2;
        $this->hold('g-0.lock');
        $this->hold('g-1.lock');
        $this->freeOnSleep[2] = 'g-1.lock';
        $this->assertSame('ok', $this->gate()->run(7, fn () => 'ok'));
        $this->assertSame([50, 50], $this->slept);
    }

    public function testASlotNeverFreedIsBusyAtTheDeadline(): void
    {
        foreach (range(0, 3) as $k) {
            $this->hold("u7-$k.lock");
        }
        $ran = false;
        $this->assertBusy(fn () => $this->gate()->run(7, function () use (&$ran): void {
            $ran = true;
        }));
        $this->assertFalse($ran);
        $this->assertCount(40, $this->slept, '2000 ms in 50 ms steps');
        $this->assertSame(2000, array_sum($this->slept));
    }

    /** The 2 s is one budget for the whole run: the user wait and the global wait share it. */
    public function testTheUserAndGlobalWaitsShareOneDeadlineAndTheUserSlotIsReleased(): void
    {
        $this->global = 1;
        foreach (range(0, 3) as $k) {
            $this->hold("u7-$k.lock");
        }
        $this->hold('g-0.lock');
        $this->freeOnSleep[30] = 'u7-1.lock';
        $this->assertBusy(fn () => $this->gate()->run(7, fn () => null));
        $this->assertSame(2000, array_sum($this->slept));
        $this->hold('u7-1.lock');
    }

    public function testTheWaitAndStepAreInjectable(): void
    {
        $this->hold('g-0.lock');
        $this->global = 1;
        $slept = [];
        $gate = new InflightGate($this->dir, fn (): int => $this->global, InflightGate::PER_USER, null, 300, 100, function (int $ms) use (&$slept): void {
            $slept[] = $ms;
        });
        $this->assertBusy(fn () => $gate->run(7, fn () => null));
        $this->assertSame([100, 100, 100], $slept);
    }

    /**
     * The reviewer's probe as a test: 10 real processes for one user, each holding a slot for 300 ms. With the default
     * 2 s wait every one gets through (4 slots, three waves); before, 6 of 10 were Busy at once.
     */
    public function testTenConcurrentProcessesForOneUserAllGetThrough(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled');
        }
        $root = dirname(__DIR__);
        $code = 'namespace Kanboard\\Plugin\\Matinee\\Model;'
            . '[, $root, $dir] = $argv;'
            . 'require $root."/Model/MatineeBusyException.php"; require $root."/Model/InflightGate.php";'
            . '$g = new InflightGate($dir, fn () => 8);'
            . 'try { $g->run(7, function () { usleep(300000); }); echo "ok"; } catch (MatineeBusyException $e) { echo "busy"; }';
        $procs = [];
        for ($i = 0; $i < 10; $i++) {
            $p = proc_open([PHP_BINARY, '-n', '-r', $code, '--', $root, $this->dir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($p);
            $procs[] = [$p, $pipes];
        }
        $out = [];
        foreach ($procs as [$p, $pipes]) {
            $out[] = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
        }
        $this->assertSame(array_fill(0, 10, 'ok'), $out);
    }
}
