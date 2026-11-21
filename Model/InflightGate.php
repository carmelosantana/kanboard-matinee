<?php

namespace Kanboard\Plugin\Matinee\Model;

/**
 * Bounds the Plex requests a browser can have in flight at once (M1): every buffered or short streamed Plex call made
 * for a browser request (a direct part window, the HLS master, a child playlist or segment, art, a listing or the
 * player page's lookups) runs inside run(). The long mp3 transcode and the HLS session itself are not gated here; the
 * long-stream caps (TranscodeModel) bound those.
 *
 * - A slot is an exclusive, non-blocking flock on a file in $dir (created 0700): first one of the user's
 *   `u<id>-<k>.lock` for k < $perUser, then one of the install's `g-<k>.lock` for k < the global cap. Either full
 *   releases what was taken and is Busy with Retry-After INFLIGHT_RETRY_AFTER.
 * - flock belongs to the open file description, so a slot held through another descriptor, even in this process,
 *   counts; and the kernel drops it when the worker dies, so a crash never leaks a slot (no DB rows to sweep).
 * - Slots are released in a `finally`. A slot file that can't be opened fails closed: Busy, with one fixed log line.
 */
final class InflightGate
{
    /** Requests in flight per user; a constant by design (only the global cap is a setting). */
    public const PER_USER = 4;
    public const UNAVAILABLE_LOG = 'Matinee: in-flight gate unavailable';

    /**
     * @param \Closure(): int $globalCap read on each run, so a saved setting applies at once
     * @param (\Closure(string): void)|null $log gets UNAVAILABLE_LOG when a slot file can't be opened
     */
    public function __construct(
        private readonly string $dir,
        private readonly \Closure $globalCap,
        private readonly int $perUser = self::PER_USER,
        private readonly ?\Closure $log = null,
    ) {
    }

    /**
     * Runs $call holding one of $userId's slots and one global slot, and returns what it returns.
     *
     * @template T
     * @param \Closure(): T $call
     * @return T
     * @throws MatineeBusyException a cap is full (nothing of $call ran)
     */
    public function run(int $userId, \Closure $call): mixed
    {
        $held = [];
        try {
            $held[] = $this->take('u' . $userId . '-', $this->perUser);
            $held[] = $this->take('g-', max(1, ($this->globalCap)()));
            return $call();
        } finally {
            foreach ($held as $fh) {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
    }

    /**
     * The first free slot of $prefix0..$prefix($slots - 1), locked.
     *
     * @return resource
     * @throws MatineeBusyException every slot is held, or a slot file can't be opened
     */
    private function take(string $prefix, int $slots)
    {
        if (! is_dir($this->dir) && ! @mkdir($this->dir, 0700, true) && ! is_dir($this->dir)) {
            $this->unavailable();
        }
        for ($k = 0; $k < $slots; $k++) {
            $fh = @fopen($this->dir . '/' . $prefix . $k . '.lock', 'c');
            if ($fh === false) {
                $this->unavailable();
            }
            if (flock($fh, LOCK_EX | LOCK_NB)) {
                return $fh;
            }
            fclose($fh);
        }
        throw new MatineeBusyException(MatineeBusyException::INFLIGHT_RETRY_AFTER);
    }

    private function unavailable(): never
    {
        if ($this->log !== null) {
            ($this->log)(self::UNAVAILABLE_LOG);
        }
        throw new MatineeBusyException(MatineeBusyException::INFLIGHT_RETRY_AFTER);
    }
}
