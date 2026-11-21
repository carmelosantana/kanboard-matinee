<?php

use PHPUnit\Framework\TestCase;

/**
 * player.js's behaviour (plan P3 Task 19): runs Test/js/player.test.mjs, a zero-dependency node:test file that loads
 * the real Assets/js/player.js in a vm context with stubbed DOM, fetch, timers, sendBeacon, sessionStorage and Hls.
 * Skipped where node is not on PATH.
 */
class PlayerJsTest extends TestCase
{
    public function testPlayerJsPassesItsNodeTests(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is not on PATH');
        }
        exec(escapeshellarg($node).' --test '.escapeshellarg(__DIR__.'/js/player.test.mjs').' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertMatchesRegularExpression('/^# fail 0$/m', implode("\n", $out));
        $this->assertMatchesRegularExpression('/^# pass [1-9]\d*$/m', implode("\n", $out));
    }
}
