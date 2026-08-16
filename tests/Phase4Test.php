<?php
/**
 * Tests for Phase 4: Site Health defaults, retention, dark theme variables.
 *
 * @package CommonGoals
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use CommonGoals\SiteHealth;
use CommonGoals\Migrator;

class Phase4Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $GLOBALS['wpdb'] = new class {
            public $prefix = 'wp_';

            public function get_var($sql = null): ?string
            {
                return '0';
            }

            public function get_row($sql = null): ?object
            {
                return null;
            }

            public function query($sql = null): void
            {
            }

            public function prepare($sql = null, ...$args): string
            {
                return $sql;
            }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_default_retention_is_90_days(): void
    {
        Functions\when('get_option')->justReturn(SiteHealth::DEFAULT_RETENTION);

        $this->assertSame(90, SiteHealth::get_retention_days());
    }

    public function test_cron_hook_is_defined(): void
    {
        $this->assertSame('common_goals_cleanup_events', SiteHealth::CRON_HOOK);
    }

    public function test_cron_interval_name_is_defined(): void
    {
        $this->assertSame('common_goals_daily', SiteHealth::CRON_INTERVAL_NAME);
    }

    public function test_retention_option_name_is_defined(): void
    {
        $this->assertSame('common_goals_event_retention_days', SiteHealth::RETENTION_OPTION);
    }

    public function test_register_tests_adds_common_goals_entries(): void
    {
        Functions\when('__')->returnArg();

        $result = SiteHealth::register_tests(['direct' => []]);

        $this->assertArrayHasKey('common_goals_tables', $result['direct']);
        $this->assertArrayHasKey('common_goals_schema', $result['direct']);
    }

    public function test_register_cron_interval_adds_daily_schedule(): void
    {
        Functions\when('__')->returnArg();

        $schedules = SiteHealth::register_cron_interval([]);

        $this->assertArrayHasKey('common_goals_daily', $schedules);
        $this->assertSame(DAY_IN_SECONDS, $schedules['common_goals_daily']['interval']);
    }

    public function test_schema_version_test_returns_good_when_matching(): void
    {
        Functions\when('get_option')->justReturn(COMMON_GOALS_VERSION);
        Functions\when('__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_url')->returnArg();

        $result = SiteHealth::test_schema_version();

        $this->assertSame('good', $result['status']);
    }

    public function test_schema_version_test_returns_warning_when_behind(): void
    {
        Functions\when('get_option')->justReturn('0.1.0');
        Functions\when('__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_url')->returnArg();

        $result = SiteHealth::test_schema_version();

        $this->assertSame('warning', $result['status']);
    }

    public function test_debug_info_includes_common_goals_section(): void
    {
        Functions\when('get_option')->justReturn(COMMON_GOALS_VERSION);
        Functions\when('__')->returnArg();

        $info = SiteHealth::register_debug_info([]);

        $this->assertArrayHasKey('common-goals', $info);
        $this->assertArrayHasKey('version', $info['common-goals']['fields']);
        $this->assertArrayHasKey('schema', $info['common-goals']['fields']);
        $this->assertArrayHasKey('contributions', $info['common-goals']['fields']);
        $this->assertArrayHasKey('guides', $info['common-goals']['fields']);
    }
}
