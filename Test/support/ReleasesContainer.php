<?php
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) { http_response_code(404); exit; }

/**
 * For every test class that calls Plugin::initialize(). PHPUnit keeps every test object until the run ends, and core's
 * Base keeps the dispatcher, whose listeners (Matinee's among them) hold the whole container. Calling
 * releaseContainer() after parent::tearDown() drops it, so the full suite stays under PHP's 128 MiB default
 * (run-realdb-tests.sh).
 */
trait MatineeReleasesContainer
{
    private function releaseContainer(): void
    {
        $this->dispatcher = null;
        gc_collect_cycles();
    }
}
