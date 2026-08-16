<?php
/**
 * Base test case for property-based tests.
 *
 * Provides assertProperty() which runs a check many times with a deterministic
 * seeded RNG, resetting the WpdbSpy between iterations. Any failure reports the
 * seed and iteration so the counter-example is fully reproducible.
 *
 * @package CommonGoals\Tests\Property\Support
 */

namespace CommonGoals\Tests\Property\Support;

use CommonGoals\Tests\Unit\Support\UnitTestCase;
use CommonGoals\Tests\Unit\Support\WpdbSpy;

abstract class PropertyTestCase extends UnitTestCase
{
    /** Fixed suite seed; override via CG_PROPERTY_SEED env var. */
    protected function seed(): int
    {
        $env = getenv('CG_PROPERTY_SEED');
        return $env !== false && is_numeric($env) ? (int) $env : 20260726;
    }

    /**
     * Runs $check $iterations times. Each call receives a PropertyRng seeded
     * deterministically from the base seed plus the iteration index.
     *
     * @param callable(PropertyRng): void $check
     */
    protected function assertProperty(callable $check, int $iterations = 200, ?string $label = null): void
    {
        $base = $this->seed();
        for ($i = 0; $i < $iterations; $i++) {
            $this->resetSpy();
            $rng = new PropertyRng($base + $i);
            try {
                $check($rng);
            } catch (\Throwable $e) {
                $this->fail(
                    ($label ?? 'Property') . " failed at iteration {$i} (seed=" . ($base + $i) . "): " . $e->getMessage()
                );
            }
        }
        $this->addToAssertionCount(1);
    }

    /**
     * Replaces the wpdb spy with a fresh instance to keep iterations isolated.
     */
    protected function resetSpy(): void
    {
        $this->wpdb = new WpdbSpy();
        $GLOBALS['wpdb'] = $this->wpdb;
        $_GET = [];
        $_POST = [];
        $_SERVER = ['REMOTE_ADDR' => '203.0.113.7'];
    }
}
