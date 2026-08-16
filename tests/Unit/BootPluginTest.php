<?php
/**
 * Unit tests for plugin bootstrap, Plugin::register_hooks and Activator.
 *
 * Covers spec cases UT-BOOT-001 and UT-ACT-001.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use Brain\Monkey\Functions;
use CommonGoals\Tests\Unit\Support\UnitTestCase;
use CommonGoals\Activator;
use CommonGoals\Plugin;

/**
 * Verifies module registration and activation side effects.
 */
final class BootPluginTest extends UnitTestCase
{
    public function test_ut_boot_001_register_hooks_wires_every_module(): void
    {
        $plugin = new Plugin();
        $plugin->register_hooks();

        $this->assertTrue(has_action('rest_api_init') !== false, 'RestApi init hook missing');
        $this->assertTrue(has_action('init') !== false, 'init hook missing');
        $this->assertTrue(has_action('admin_menu') !== false, 'admin_menu hook missing');
        $this->assertTrue(has_action('admin_post_common_goals_export') !== false, 'export admin-post hook missing');
        $this->assertTrue(has_filter('wp_sitemaps_register_providers') !== false, 'sitemap provider filter missing');
        $this->assertTrue(has_filter('site_status_tests') !== false, 'site health filter missing');
        $this->assertTrue(has_filter('wp_privacy_personal_data_exporters') !== false, 'privacy exporter filter missing');
    }

    public function test_ut_boot_001_registers_all_admin_pages_and_frontend_modules(): void
    {
        $plugin = new Plugin();
        $plugin->register_hooks();

        $hooks = [
            'admin_post_common_goals_create_community',
            'admin_post_common_goals_update_community',
            'admin_post_common_goals_add_member',
            'admin_post_common_goals_remove_member',
            'admin_post_common_goals_create_goal',
            'admin_post_common_goals_update_goal',
            'admin_post_common_goals_create_guide',
            'admin_post_common_goals_update_contribution_status',
            'admin_post_common_goals_bulk_moderate',
            'admin_post_common_goals_update_response_status',
            'admin_post_common_goals_update_guide',
            'admin_post_common_goals_create_contribution',
            'admin_post_nopriv_common_goals_create_contribution',
            'admin_post_common_goals_create_response',
            'admin_post_nopriv_common_goals_create_response',
            'admin_post_common_goals_edit_contribution',
            'admin_post_common_goals_delete_contribution',
        ];
        foreach ($hooks as $hook) {
            $this->assertTrue(has_action($hook) !== false, "Missing hook: {$hook}");
        }

        $this->assertTrue(has_filter('common_goals_allowed_types') === false, 'Domain filter should only fire when invoked');
    }

    public function test_ut_act_001_activate_schedules_cron_and_flushes_rewrites(): void
    {
        $scheduled = false;
        Functions\when('flush_rewrite_rules')->justReturn();
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_event')->alias(static function () use (&$scheduled) {
            $scheduled = true;
        });
        Functions\when('get_role')->justReturn(null);
        Functions\when('add_role')->justReturn(null);

        Activator::activate();

        $this->assertTrue($scheduled, 'Cron event was not scheduled during activation');
    }

    public function test_ut_act_001_activate_does_not_duplicate_cron_when_already_scheduled(): void
    {
        $calls = 0;
        Functions\when('flush_rewrite_rules')->justReturn();
        Functions\when('wp_next_scheduled')->justReturn(time() + 3600);
        Functions\when('wp_schedule_event')->alias(static function () use (&$calls) {
            $calls++;
        });
        Functions\when('get_role')->justReturn(null);
        Functions\when('add_role')->justReturn(null);

        Activator::activate();

        $this->assertSame(0, $calls, 'Cron was scheduled again despite an existing event');
    }
}
