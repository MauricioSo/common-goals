<?php
/**
 * Tests for the Migrator class: version comparison and idempotency.
 *
 * @package CommonGoals
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use CommonGoals\Migrator;

class MigratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $GLOBALS['wpdb'] = new class {
            public $prefix = 'wp_';
            public $queries_run = 0;

            public function get_charset_collate(): string
            {
                return 'DEFAULT CHARSET=utf8mb4';
            }

            public function query($sql = null): void
            {
                $this->queries_run++;
            }

            public function get_var($sql = null)
            {
                return null;
            }

            public function insert($table, $data, $format = null): bool
            {
                $this->queries_run++;

                return true;
            }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_run_does_nothing_when_versions_match(): void
    {
        Functions\when('get_option')->justReturn(COMMON_GOALS_VERSION);
        Functions\when('update_option')->justReturn(true);

        Migrator::run();

        $this->assertSame(0, $GLOBALS['wpdb']->queries_run);
    }

    public function test_run_executes_migrations_when_behind(): void
    {
        Functions\when('get_option')->justReturn('0');
        Functions\when('update_option')->justReturn(true);
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('get_bloginfo')->justReturn('Test Site');
        Functions\when('sanitize_title')->alias(static fn ($value) => strtolower(str_replace(' ', '-', $value)));
        Functions\when('get_current_user_id')->justReturn(1);

        Migrator::run();

        $this->assertGreaterThan(0, $GLOBALS['wpdb']->queries_run);
    }

    public function test_migration_map_contains_expected_versions(): void
    {
        $reflection = new ReflectionClass(Migrator::class);
        $method = $reflection->getMethod('migration_map');
        $method->setAccessible(true);

        $map = $method->invoke(null);

        $this->assertArrayHasKey('0.1.0', $map);
        $this->assertArrayHasKey('0.2.0', $map);
        $this->assertArrayHasKey('0.9.0', $map);
    }
}
