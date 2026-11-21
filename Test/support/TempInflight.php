<?php
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) { http_response_code(404); exit; }

use Kanboard\Plugin\Matinee\Model\InflightGate;
use Kanboard\Plugin\Matinee\Model\Settings;

/**
 * For every test class whose requests reach Plex through the in-flight gate (M1): the gate on a temp dir of the
 * test's own, never DATA_DIR (read-only in the real-DB runs), with helpers to fill its slots.
 */
trait MatineeTempInflight
{
    private string $inflightDir;
    /** @var list<resource> in-flight slot files the test holds */
    private array $inflightHeld = [];

    /** Call after Plugin::initialize(). */
    private function useTempInflightGate(): void
    {
        $this->inflightDir = sys_get_temp_dir().'/matinee-inflight-'.bin2hex(random_bytes(6));
        $this->container->offsetUnset('matineeInflightGate');
        $this->container['matineeInflightGate'] = fn ($c): InflightGate => new InflightGate(
            $this->inflightDir,
            static fn (): int => (new Settings($c))->inflightGlobal()
        );
    }

    /**
     * Locks the in-flight slot files `<prefix><k>.lock` for k < $n through descriptors of the test's own (another
     * descriptor in this process counts, as flock is per open file description), as concurrent requests would.
     */
    private function holdInflight(string $prefix, int $n): void
    {
        if (! is_dir($this->inflightDir)) {
            mkdir($this->inflightDir, 0700, true);
        }
        for ($k = 0; $k < $n; $k++) {
            $fh = fopen($this->inflightDir.'/'.$prefix.$k.'.lock', 'c');
            $this->assertTrue(flock($fh, LOCK_EX | LOCK_NB), $prefix.$k);
            $this->inflightHeld[] = $fh;
        }
    }

    /** Releases every held slot and removes the temp dir. */
    private function cleanInflight(): void
    {
        foreach ($this->inflightHeld as $fh) {
            fclose($fh);
        }
        $this->inflightHeld = [];
        foreach (glob($this->inflightDir.'/*.lock') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->inflightDir);
    }
}
