<?php

use Kanboard\Plugin\Matinee\Http\UrlGuard;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

/**
 * The DNS seam is for tests only: without MATINEE_TESTING (defined by the tests that use the seam, never by the
 * plugin) setResolverForTesting() changes nothing. A constant cannot be undefined, so this runs in its own process.
 */
class UrlGuardSeamTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheResolverSeamIsInertOutsideTests(): void
    {
        $this->assertFalse(defined('MATINEE_TESTING'));
        $resolver = new ReflectionProperty(UrlGuard::class, 'resolver');
        UrlGuard::setResolverForTesting(fn (string $host): array => ['93.184.216.34']);
        $this->assertNull($resolver->getValue());
    }
}
