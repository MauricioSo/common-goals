<?php
/**
 * Unit tests for SiteHealth tests, cron scheduling and event retention.
 *
 * Covers spec case UT-HEALTH-001.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use Brain\Monkey\Functions;
use CommonGoals\Database;
use CommonGoals\SiteHealth;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class SiteHealthUnitTest extends UnitTestCase
{
    public function test_ut_health_001_register_hooks_registers_tests_debug_and_cron(): void
    {
        SiteHealth::register_hooks();

        $this->assertNotFalse(has_filter('site_status_tests'));
        $this->assertNotFalse(has_filter('debug_information'));
        $this->assertNotFalse(has_action(SiteHealth::CRON_HOOK));
        // WordPress treats add_action and add_filter as equivalent; the plugin uses
        // add_action for cron_schedules, so it lands in the action storage.
        $this->assertNotFalse(has_action('cron_schedules'));
    }

    public function test_ut_health_001_register_tests_adds_two_direct_tests(): void
    {
        $tests = SiteHealth::register_tests(['direct' => []]);

        $this->assertArrayHasKey('common_goals_tables', $tests['direct']);
        $this->assertArrayHasKey('common_goals_schema', $tests['direct']);
    }

    public function test_ut_health_001_tables_test_good_when_all_present(): void
    {
        // Seven SHOW TABLES checks must each return the table name.
        foreach ([
            Database::communities_table(),
            Database::community_members_table(),
            Database::goals_table(),
            Database::contributions_table(),
            Database::responses_table(),
            Database::guides_table(),
            Database::events_table(),
        ] as $table) {
            $this->wpdb->queue_get_var($table);
        }

        $result = SiteHealth::test_tables_exist();

        $this->assertSame('good', $result['status']);
    }

    public function test_ut_health_001_tables_test_critical_when_any_missing(): void
    {
        // All get_var calls return null => all missing.
        $result = SiteHealth::test_tables_exist();

        $this->assertSame('critical', $result['status']);
        $this->assertStringContainsString('wp_cg_communities', $result['description']);
    }

    public function test_ut_health_001_schema_test_good_when_up_to_date(): void
    {
        Functions\when('get_option')->justReturn(COMMON_GOALS_VERSION);

        $result = SiteHealth::test_schema_version();

        $this->assertSame('good', $result['status']);
    }

    public function test_ut_health_001_schema_test_warning_when_behind(): void
    {
        Functions\when('get_option')->justReturn('0.1.0');

        $result = SiteHealth::test_schema_version();

        $this->assertSame('warning', $result['status']);
    }

    public function test_ut_health_001_cron_interval_is_exactly_one_day(): void
    {
        $schedules = SiteHealth::register_cron_interval([]);

        $this->assertArrayHasKey(SiteHealth::CRON_INTERVAL_NAME, $schedules);
        $this->assertSame(DAY_IN_SECONDS, $schedules[SiteHealth::CRON_INTERVAL_NAME]['interval']);
    }

    public function test_ut_health_001_register_cron_interval_preserves_existing_schedules(): void
    {
        $schedules = SiteHealth::register_cron_interval(['hourly' => ['interval' => 3600, 'display' => 'Hourly']]);

        $this->assertArrayHasKey('hourly', $schedules);
        $this->assertArrayHasKey(SiteHealth::CRON_INTERVAL_NAME, $schedules);
    }

    public function test_ut_health_001_schedule_cron_schedules_when_not_already_scheduled(): void
    {
        $scheduled = false;
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_event')->alias(static function () use (&$scheduled) {
            $scheduled = true;
        });

        SiteHealth::schedule_cron();

        $this->assertTrue($scheduled);
    }

    public function test_ut_health_001_schedule_cron_does_not_duplicate(): void
    {
        $calls = 0;
        Functions\when('wp_next_scheduled')->justReturn(time() + 3600);
        Functions\when('wp_schedule_event')->alias(static function () use (&$calls) {
            $calls++;
        });

        SiteHealth::schedule_cron();

        $this->assertSame(0, $calls);
    }

    public function test_ut_health_001_cleanup_old_events_uses_strict_less_than_cutoff(): void
    {
        SiteHealth::cleanup_old_events();

        $this->assertSame(1, $this->wpdb->count_method('query'));
        $sql = $this->wpdb->sql_strings()[0];
        $this->assertStringContainsString('DELETE FROM wp_cg_events', $sql);
        $this->assertStringContainsString('created_at <', $sql);
    }

    public function test_ut_health_001_retention_days_defaults_to_90(): void
    {
        Functions\when('get_option')->alias(static fn($name, $default = false) => $default);

        $this->assertSame(90, SiteHealth::get_retention_days());
    }
}
