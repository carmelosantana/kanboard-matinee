<?php

namespace Kanboard\Plugin\Matinee\Model;

/**
 * Bounds the Plex requests a browser can have in flight at once (M1): every buffered or short streamed Plex call made
 * for a browser request (a direct part window, the HLS master, a child playlist or segment, art, a listing or the
 * player page's lookups) runs inside run(). The long mp3 transcode and the HLS session itself are not gated here; the
 * long-stream caps (TranscodeModel) bound those.
 *
 * - A slot is an exclusive, non-blocking flock on a file in $dir (created 0700): first one of the user's
 *   `u<id>-<k>.lock` for k < $perUser, then one of the install's `g-<k>.lock` for k < the global cap.
 * - While the slots are full, the run tries them again every STEP_MS (50 ms), for up to WAIT_MS (2 s) in all across
 *   both caps, so a library page's burst of posters queues briefly instead of failing (an <img> never retries). A
 *   waiting run has no Plex call in flight, so it costs a worker at most 2 s. Still full at the deadline, it releases
 *   what it took and is Busy with Retry-After INFLIGHT_RETRY_AFTER.
 * - flock belongs to the open file description, so a slot held through another descriptor, even in this process,
 *   counts; and the kernel drops it when the worker dies, so a crash never leaks a slot (no DB rows to sweep).
 * - Slots are released in a `finally`. A slot file that can't be opened fails closed: Busy, with one fixed log line.
 */
final class InflightGate
{
    /** Requests in flight per user; a constant by design (only the global cap is a setting). */
    public const PER_USER = 4;
    public const UNAVAILABLE_LOG = 'Matinee: in-flight gate unavailable';
    public const WAIT_MS = 2000;
    public const STEP_MS = 50;

    /**
     * @param \Closure(): int $globalCap read on each run, so a saved setting applies at once
     * @param (\Closure(string): void)|null $log gets UNAVAILABLE_LOG when a slot file can't be opened
     * @param int $waitMs how long a run waits in all for full slots to free before it is Busy
     * @param int $stepMs how often a waiting run tries the slots again
     * @param (\Closure(int): void)|null $sleep sleeps the given ms between tries (usleep); tests count the calls
     */
    public function __construct(
        private readonly string $dir,
        private readonly \Closure $globalCap,
        private readonly int $perUser = self::PER_USER,
        private readonly ?\Closure $log = null,
        private readonly int $waitMs = self::WAIT_MS,
        private readonly int $stepMs = self::STEP_MS,
        private readonly ?\Closure $sleep = null,
    ) {
    }

    /**
     * Runs $call holding one of $userId's slots and one global slot, and returns what it returns.
     *
     * @template T
     * @param \Closure(): T $call
     * @return T
     * @throws MatineeBusyException a cap is still full after the wait (nothing of $call ran)
     */
    public function run(int $userId, \Closure $call): mixed
    {
        $held = [];
        $polls = intdiv(max(0, $this->waitMs), max(1, $this->stepMs));
        try {
            $held[] = $this->take('u' . $userId . '-', $this->perUser, $polls);
            $held[] = $this->take('g-', max(1, ($this->globalCap)()), $polls);
            return $call();
        } finally {
            foreach ($held as $fh) {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
    }

    /**
     * The first free slot of $prefix0..$prefix($slots - 1), locked; while every one is held, polls again every $stepMs
     * for as long as $polls (the run's shared wait budget, in steps) lasts.
     *
     * @return resource
     * @throws MatineeBusyException every slot is still held when the budget runs out, or a slot file can't be opened
     */
    private function take(string $prefix, int $slots, int &$polls)
    {
        if (! is_dir($this->dir) && ! @mkdir($this->dir, 0700, true) && ! is_dir($this->dir)) {
            $this->unavailable();
        }
        while (true) {
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
            if ($polls <= 0) {
                throw new MatineeBusyException(MatineeBusyException::INFLIGHT_RETRY_AFTER);
            }
            $polls--;
            ($this->sleep ?? static fn (int $ms) => usleep($ms * 1000))($this->stepMs);
        }
    }

    private function unavailable(): never
    {
        if ($this->log !== null) {
            ($this->log)(self::UNAVAILABLE_LOG);
        }
        throw new MatineeBusyException(MatineeBusyException::INFLIGHT_RETRY_AFTER);
    }
}
