<?php
/**
 * Unit tests for Migrator version comparison, ordering and idempotency.
 *
 * Covers spec cases UT-MIG-001 and UT-MIG-002. Ordering is verified through
 * the real side effects of each migration callback (dbDelta, ALTER queries and
 * the default-community insert), using a stateful option store so that a second
 * run sees the advanced version.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use Brain\Monkey\Functions;
use CommonGoals\Migrator;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class MigratorUnitTest extends UnitTestCase
{
    private int $dbdelta_calls = 0;
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbdelta_calls = 0;
        $this->options = [Migrator::OPTION_NAME => '0'];

        Functions\when('dbDelta')->alias(function ($sql) {
            $this->dbdelta_calls++;
            return [];
        });
        Functions\when('get_bloginfo')->justReturn('Test Site');
        Functions\when('get_option')->alias(fn($name, $default = false) => array_key_exists($name, $this->options) ? $this->options[$name] : $default);
        Functions\when('update_option')->alias(function ($name, $value) {
            $this->options[$name] = $value;
            return true;
        });
    }

    public function test_ut_mig_001_run_does_nothing_when_installed_matches_plugin(): void
    {
        $this->options = [Migrator::OPTION_NAME => COMMON_GOALS_VERSION];

        Migrator::run();

        $this->assertSame(0, $this->dbdelta_calls);
        $this->assertSame(0, $this->wpdb->count_method('query'));
        $this->assertSame(0, $this->wpdb->count_method('insert'));
    }

    public function test_ut_mig_001_run_does_nothing_when_installed_is_higher(): void
    {
        $this->options = [Migrator::OPTION_NAME => '99.0.0'];

        Migrator::run();

        $this->assertSame(0, $this->dbdelta_calls);
    }

    public function test_ut_mig_002_from_zero_runs_all_migrations_in_order(): void
    {
        $this->runFrom('0');

        $this->assertSame(19, $this->dbdelta_calls, '0.1.0 tables + later forum tables must be dbDelta-managed');
        $this->assertSame(14, $this->wpdb->count_method('query'), 'Index/column migrations must run in version order');
        $this->assertSame(1, $this->wpdb->count_method('insert'));
        $this->assertSame(COMMON_GOALS_VERSION, $this->options[Migrator::OPTION_NAME]);
    }

    public function test_ut_mig_002_from_0_1_0_skips_first_migration(): void
    {
        $this->runFrom('0.1.0');

        $this->assertSame(7, $this->dbdelta_calls);
        $this->assertSame(14, $this->wpdb->count_method('query'));
        $this->assertSame(1, $this->wpdb->count_method('insert'));
    }

    public function test_ut_mig_002_from_0_2_0_runs_only_0_9_0(): void
    {
        $this->runFrom('0.2.0');

        $this->assertSame(7, $this->dbdelta_calls);
        $this->assertSame(10, $this->wpdb->count_method('query'));
        $this->assertSame(1, $this->wpdb->count_method('insert'));
    }

    public function test_ut_mig_002_from_0_9_0_runs_later_forum_migrations(): void
    {
        $this->runFrom('0.9.0');

        $this->assertSame(5, $this->dbdelta_calls);
        $this->assertSame(8, $this->wpdb->count_method('query'));
        $this->assertSame(0, $this->wpdb->count_method('insert'));
        $this->assertSame(COMMON_GOALS_VERSION, $this->options[Migrator::OPTION_NAME]);
    }

    public function test_ut_mig_002_default_community_slug_uses_sanitize_title(): void
    {
        Functions\when('get_bloginfo')->justReturn('Mi Sitio');
        $this->runFrom('0.2.0');

        $inserts = array_filter($this->wpdb->calls, static fn($c) => $c['method'] === 'insert');
        $this->assertNotEmpty($inserts);
        $last = array_slice($inserts, -1)[0];
        $this->assertSame('mi-sitio', $last['extra']['data']['slug']);
        $this->assertSame('active', $last['extra']['data']['status']);
    }

    public function test_ut_mig_002_does_not_seed_default_community_when_communities_exist(): void
    {
        // 0.9.0 consults: has_community_id, has_community_index, COUNT(*) communities.
        $this->wpdb->queue_get_var(null);
        $this->wpdb->queue_get_var(null);
        $this->wpdb->queue_get_var('3');
        $this->runFrom('0.2.0');

        $this->assertSame(0, $this->wpdb->count_method('insert'));
    }

    public function test_ut_mig_002_re_running_after_completion_is_idempotent(): void
    {
        $this->runFrom('0');
        $firstDeltas = $this->dbdelta_calls;
        $firstQueries = $this->wpdb->count_method('query');
        $firstInserts = $this->wpdb->count_method('insert');

        // Second run sees the option advanced to plugin version and does nothing.
        Migrator::run();

        $this->assertSame($firstDeltas, $this->dbdelta_calls);
        $this->assertSame($firstQueries, $this->wpdb->count_method('query'));
        $this->assertSame($firstInserts, $this->wpdb->count_method('insert'));
    }

    private function runFrom(string $installed): void
    {
        $this->options = [Migrator::OPTION_NAME => $installed];
        Migrator::run();
    }
}
