<?php
/**
 * Unit tests for the admin pages and their admin-post handlers.
 *
 * Covers spec cases UT-ADM-001 to UT-ADM-005. Handlers that end with exit() are
 * exercised through the RedirectCatcher, which turns wp_safe_redirect into a
 * catchable exception.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use Brain\Monkey\Functions;
use CommonGoals\Admin\CommunitiesAdminPage;
use CommonGoals\Admin\EventsAdminPage;
use CommonGoals\Admin\GoalsAdminPage;
use CommonGoals\Admin\GuidesAdminPage;
use CommonGoals\Admin\ContributionsAdminPage;
use CommonGoals\Admin\SettingsPage;
use CommonGoals\Capabilities;
use CommonGoals\Tests\Unit\Support\HandlerCatcher;
use CommonGoals\Tests\Unit\Support\RedirectException;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class AdminPagesUnitTest extends UnitTestCase
{
    use HandlerCatcher;

    public function test_ut_adm_001_every_admin_page_registers_admin_menu(): void
    {
        (new CommunitiesAdminPage())->register_hooks();
        (new GoalsAdminPage())->register_hooks();
        (new ContributionsAdminPage())->register_hooks();
        (new GuidesAdminPage())->register_hooks();
        (new EventsAdminPage())->register_hooks();

        // admin_menu is an action hook each page attaches a callback to.
        $this->assertNotFalse(has_action('admin_menu'));
    }

    public function test_ut_adm_001_communities_registers_four_admin_post_actions(): void
    {
        (new CommunitiesAdminPage())->register_hooks();

        foreach ([
            'admin_post_common_goals_create_community',
            'admin_post_common_goals_update_community',
            'admin_post_common_goals_add_member',
            'admin_post_common_goals_remove_member',
        ] as $hook) {
            $this->assertNotFalse(has_action($hook), "Missing hook: {$hook}");
        }
    }

    public function test_ut_adm_001_events_menu_hidden_for_users_without_access(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(0);
        $registered = false;
        Functions\when('add_submenu_page')->alias(static function () use (&$registered) {
            $registered = true;
        });

        (new EventsAdminPage())->register_admin_menu();

        $this->assertFalse($registered, 'Events menu must not register when user lacks access');
    }

    public function test_ut_adm_001_goals_menu_hidden_for_users_without_access(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(0);
        $registered = false;
        Functions\when('add_menu_page')->alias(static function () use (&$registered) {
            $registered = true;
        });
        Functions\when('add_submenu_page')->alias(static function () use (&$registered) {
            $registered = true;
        });

        (new GoalsAdminPage())->register_admin_menu();

        $this->assertFalse($registered, 'Goals top menu must not register when user lacks access');
    }

    public function test_ut_adm_002_create_community_redirects_invalid_community_when_name_empty(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_POST = ['community_name' => '', 'community_description' => ''];

        $this->expectRedirectNotice('invalid_community', static function () {
            (new CommunitiesAdminPage())->handle_create_community();
        });

        $this->assertSame(0, $this->wpdb->count_method('insert'));
    }

    public function test_ut_adm_002_create_community_requires_global_manage_capability(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $this->expectWpDie(static function () {
            (new CommunitiesAdminPage())->handle_create_community();
        });

        $this->assertSame(0, $this->wpdb->count_method('insert'));
    }

    public function test_ut_adm_002_create_community_appends_suffix_on_duplicate_slug(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(3);
        Functions\when('wp_rand')->justReturn(777);
        $_POST = ['community_name' => 'Alpha', 'community_description' => ''];
        // existing slug count > 0 triggers suffix.
        $this->wpdb->queue_get_var('1');

        $e = $this->captureRedirect(static function () {
            (new CommunitiesAdminPage())->handle_create_community();
        });

        $this->assertStringContainsString('community_created', $e->url);
        $inserts = array_values(array_filter($this->wpdb->calls, static fn($c) => $c['method'] === 'insert'));
        $insert = $inserts[0];
        $this->assertSame('alpha-777', $insert['extra']['data']['slug']);
    }

    public function test_ut_adm_002_create_community_db_failure_redirects_db_error(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(3);
        $_POST = ['community_name' => 'Beta', 'community_description' => ''];
        $this->wpdb->queue_get_var('0');
        $this->wpdb->queue_insert(false);

        $this->expectRedirectNotice('db_error', static function () {
            (new CommunitiesAdminPage())->handle_create_community();
        });
    }

    public function test_ut_adm_002_update_community_rejects_invalid_status(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(3);
        $_POST = ['community_id' => 1, 'community_name' => 'X', 'community_status' => 'frozen'];

        $this->expectRedirectNotice('invalid_community', static function () {
            (new CommunitiesAdminPage())->handle_update_community();
        });
    }

    public function test_ut_adm_002_add_member_rejects_invalid_role(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(3);
        $_POST = ['community_id' => 1, 'user_id' => 5, 'member_role' => 'superadmin'];

        $this->expectRedirectNotice('invalid_member', static function () {
            (new CommunitiesAdminPage())->handle_add_member();
        });
    }

    public function test_ut_adm_003_create_goal_uses_default_community_when_zero(): void
    {
        Functions\when('current_user_can')->alias(static fn($cap) => $cap === Capabilities::MANAGE);
        Functions\when('get_current_user_id')->justReturn(3);
        Functions\when('apply_filters')->returnArg(2);
        $_POST = ['community_id' => 0, 'goal_title' => 'T', 'goal_description' => 'D', 'goal_types' => ['question']];
        // get_default_community_id reads get_var; get_community reads get_row.
        $this->wpdb->queue_get_var('1');
        $this->wpdb->queue_get_row((object) ['id' => 1, 'status' => 'active']);

        try {
            $this->installHandlerCatcher();
            (new GoalsAdminPage())->handle_create_goal();
            $this->fail('Expected redirect');
        } catch (RedirectException $e) {
            $this->assertStringContainsString('goal_created', $e->url);
        }

        $inserts = array_values(array_filter($this->wpdb->calls, static fn($c) => $c['method'] === 'insert'));
        $insert = $inserts[0];
        $this->assertSame(1, $insert['extra']['data']['community_id']);
        $this->assertSame('active', $insert['extra']['data']['status']);
    }

    public function test_ut_adm_003_create_goal_rejects_missing_required_fields(): void
    {
        Functions\when('current_user_can')->alias(static fn($cap) => $cap === Capabilities::MANAGE);
        Functions\when('get_current_user_id')->justReturn(3);
        $_POST = ['community_id' => 1, 'goal_title' => '', 'goal_description' => ''];
        $this->wpdb->queue_get_row((object) ['id' => 1, 'status' => 'active']);

        $this->expectRedirectNotice('missing_required_fields', static function () {
            (new GoalsAdminPage())->handle_create_goal();
        });
    }

    public function test_ut_adm_005_update_guide_invalid_status_redirects_invalid_guide(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(3);
        $_POST = [
            'guide_id' => 1, 'guide_title' => 'T', 'guide_content' => 'C',
            'guide_slug' => 'slug', 'guide_status' => 'archived',
        ];

        $this->expectRedirectNotice('invalid_guide', static function () {
            (new GuidesAdminPage())->handle_update_guide();
        });
    }

    public function test_ut_adm_005_update_guide_redirects_invalid_guide_when_id_missing(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_POST = ['guide_id' => 0, 'guide_title' => 'T', 'guide_content' => 'C', 'guide_status' => 'draft'];

        $this->expectRedirectNotice('invalid_guide', static function () {
            (new GuidesAdminPage())->handle_update_guide();
        });
    }

    public function test_ut_set_001_settings_registers_five_options_three_sections(): void
    {
        $options = [];
        $sections = [];
        Functions\when('register_setting')->alias(static function ($group, $name) use (&$options) {
            $options[] = $name;
        });
        Functions\when('add_settings_section')->alias(static function ($id) use (&$sections) {
            $sections[] = $id;
        });
        Functions\when('add_settings_field')->justReturn();

        (new SettingsPage())->register_settings();

        $this->assertCount(5, $options);
        $this->assertContains('common_goals_allow_guest_posting', $options);
        $this->assertContains('common_goals_cleanup_on_uninstall', $options);
        $this->assertContains('common_goals_rate_limit_max', $options);
        $this->assertContains('common_goals_honeypot_enabled', $options);
        $this->assertContains(\CommonGoals\SiteHealth::RETENTION_OPTION, $options);
        $this->assertCount(3, $sections);
    }

    public function test_ut_set_001_render_page_denies_without_capability(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $this->expectWpDie(static function () {
            (new SettingsPage())->render_page();
        });
    }
}
