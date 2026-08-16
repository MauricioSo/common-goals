<?php
/**
 * Core integration tests (spec 03): bootstrap, capabilities, community/goal
 * CRUD, moderation, guides and event audit, against real WordPress + MySQL.
 *
 * @package CommonGoals\Tests\Integration
 */

namespace CommonGoals\Tests\Integration;

use CommonGoals\Admin\CommunitiesAdminPage;
use CommonGoals\Admin\ContributionsAdminPage;
use CommonGoals\Capabilities;
use CommonGoals\Database;
use CommonGoals\Domain;
use CommonGoals\Tests\Integration\Support\IntegrationTestCase;

final class CoreIntegrationTest extends IntegrationTestCase
{
    public function test_int_boot_001_plugin_registers_all_hooks(): void
    {
        $this->assertNotFalse(has_action('admin_post_common_goals_export'));
        $this->assertNotFalse(has_action('admin_post_common_goals_create_goal'));
        $this->assertNotFalse(has_action('admin_post_common_goals_create_contribution'));
        $this->assertNotFalse(has_action('admin_post_nopriv_common_goals_create_contribution'));
        $this->assertNotFalse(has_filter('wp_sitemaps_register_providers'));
        $this->assertTrue(shortcode_exists('common_goals_board'));
        $this->assertTrue(shortcode_exists('common_goals_guides'));
    }

    public function test_int_cap_001_activation_grants_caps_to_admin_and_editor(): void
    {
        Capabilities::register();
        $admin = get_role('administrator');
        $editor = get_role('editor');
        $this->assertTrue($admin->has_cap(Capabilities::MANAGE));
        $this->assertTrue($admin->has_cap(Capabilities::PUBLISH_GUIDES));
        $this->assertTrue($editor->has_cap(Capabilities::MODERATE));
        $this->assertFalse($editor->has_cap(Capabilities::MANAGE));
    }

    public function test_int_com_001_admin_creates_community_with_unique_slug(): void
    {
        $this->act_as_admin();
        $this->create_community(['slug' => 'alpha']);

        $_POST = ['community_name' => 'Beta Community', 'community_description' => ''];
        $this->with_nonce('common_goals_create_community');
        $redirect = $this->capture_redirect(static function () {
            (new CommunitiesAdminPage())->handle_create_community();
        });

        $this->assertStringContainsString('community_created', $redirect ?? '');
        $created = $this->find_community_by_slug('beta-community');
        $this->assertNotNull($created);
        $this->assertSame('active', $created->status);
    }

    public function test_int_com_002_non_manager_cannot_create_community(): void
    {
        $this->act_as_subscriber();

        $_POST = ['community_name' => 'Evil', 'community_description' => ''];
        $this->with_nonce('common_goals_create_community');

        add_filter('wp_die_handler', static function () {
            return static function () {
                throw new \RuntimeException('WP_DIE');
            };
        });
        try {
            (new CommunitiesAdminPage())->handle_create_community();
        } catch (\Throwable $e) {
            // handler invoked wp_die
        }
        remove_all_filters('wp_die_handler');

        $this->assertNull($this->find_community_by_slug('evil'));
    }

    public function test_int_goal_001_admin_creates_goal(): void
    {
        $this->act_as_admin();
        $community = $this->create_community();

        $_POST = [
            'community_id' => $community->id,
            'goal_title' => 'My Goal',
            'goal_description' => 'desc',
            'goal_beneficiary' => 'users',
            'goal_alignment_rules' => '',
            'goal_types' => ['question', 'resource'],
        ];
        $this->with_nonce('common_goals_create_goal');
        $redirect = $this->capture_redirect(static function () {
            (new \CommonGoals\Admin\GoalsAdminPage())->handle_create_goal();
        });

        $this->assertStringContainsString('goal_created', $redirect ?? '');
        global $wpdb;
        $goal = $wpdb->get_row("SELECT * FROM " . Database::goals_table() . " WHERE title = 'My Goal'");
        $this->assertNotNull($goal);
        $this->assertSame('active', $goal->status);
        $this->assertSame(['question', 'resource'], json_decode($goal->allowed_contribution_types, true));
    }

    public function test_int_mod_001_moderator_changes_contribution_status(): void
    {
        $this->act_as_admin();
        $seed = $this->seed_goal_and_contribution(get_current_user_id());
        global $wpdb;
        $wpdb->update(Database::contributions_table(), ['status' => 'pending'], ['id' => $seed->contribution_id]);

        $_POST = [
            'contribution_id' => $seed->contribution_id,
            'contribution_status' => 'open',
        ];
        $this->with_nonce('common_goals_update_contribution_status');
        $redirect = $this->capture_redirect(static function () {
            (new ContributionsAdminPage())->handle_update_contribution_status();
        });

        $this->assertStringContainsString('status_updated', $redirect ?? '');
        $this->assertSame('open', Domain::get_contribution($seed->contribution_id)->status);
    }

    public function test_int_evt_001_moderation_creates_audit_event(): void
    {
        $this->act_as_admin();
        $seed = $this->seed_goal_and_contribution(get_current_user_id());
        global $wpdb;
        $wpdb->update(Database::contributions_table(), ['status' => 'pending'], ['id' => $seed->contribution_id]);

        $_POST = ['contribution_id' => $seed->contribution_id, 'contribution_status' => 'open'];
        $this->with_nonce('common_goals_update_contribution_status');
        $this->capture_redirect(static function () {
            (new ContributionsAdminPage())->handle_update_contribution_status();
        });

        $events = $wpdb->get_var("SELECT COUNT(*) FROM " . Database::events_table() . " WHERE object_type = 'contribution' AND object_id = " . (int) $seed->contribution_id);
        $this->assertGreaterThan(0, (int) $events, 'A moderation event must be logged');
    }

    private function seed_goal_and_contribution(int $author_id = 0): object
    {
        global $wpdb;
        $community = $this->create_community();
        $goal = $this->create_goal((int) $community->id);
        $wpdb->insert(Database::contributions_table(), [
            'goal_id' => $goal->id, 'user_id' => $author_id, 'type' => 'question', 'status' => 'open', 'topic' => '', 'title' => 'C', 'body' => 'b', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
        ]);
        return (object) ['community_id' => $community->id, 'goal_id' => $goal->id, 'contribution_id' => (int) $wpdb->insert_id];
    }

    private function find_community_by_slug(string $slug): ?object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . Database::communities_table() . " WHERE slug = %s", $slug)) ?: null;
    }
}
