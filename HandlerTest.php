<?php
/**
 * Admin-post handler integration tests (spec 06).
 *
 * Covers the critical admin-post action handlers registered by the plugin.
 * Capability-denial and nonce-failure paths that trigger wp_die are tested
 * in spec 05/06's contract layer and avoided here to prevent side effects
 * from wp_die handler reconfiguration.
 *
 * @package CommonGoals\Tests\Integration
 */

namespace CommonGoals\Tests\Integration;

use CommonGoals\Admin\CommunitiesAdminPage;
use CommonGoals\Admin\ContributionsAdminPage;
use CommonGoals\Admin\GoalsAdminPage;
use CommonGoals\Admin\GuidesAdminPage;
use CommonGoals\Database;
use CommonGoals\Tests\Integration\Support\IntegrationTestCase;

final class HandlerTest extends IntegrationTestCase
{
    public function test_hnd_01_create_community_success(): void
    {
        $this->act_as_admin();
        $_POST = ['community_name' => 'Nu Alpha', 'community_description' => 'desc'];
        $this->with_nonce('common_goals_create_community');
        $r = $this->capture_redirect(static fn() => (new CommunitiesAdminPage())->handle_create_community());
        $this->assertStringContainsString('community_created', $r);
    }

    public function test_hnd_01_create_community_empty_name_redirects(): void
    {
        $this->act_as_admin();
        $_POST = ['community_name' => '', 'community_description' => ''];
        $this->with_nonce('common_goals_create_community');
        $r = $this->capture_redirect(static fn() => (new CommunitiesAdminPage())->handle_create_community());
        $this->assertStringContainsString('invalid_community', $r);
    }

    public function test_hnd_02_update_community_invalid_status(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $_POST = ['community_id' => $c->id, 'community_name' => 'X', 'community_status' => 'frozen'];
        $this->with_nonce('common_goals_update_community');
        $r = $this->capture_redirect(static fn() => (new CommunitiesAdminPage())->handle_update_community());
        $this->assertStringContainsString('invalid_community', $r);
    }

    public function test_hnd_03_add_member_invalid_role(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $_POST = ['community_id' => $c->id, 'user_id' => 1, 'member_role' => 'superadmin'];
        $this->with_nonce('common_goals_add_member');
        $r = $this->capture_redirect(static fn() => (new CommunitiesAdminPage())->handle_add_member());
        $this->assertStringContainsString('invalid_member', $r);
    }

    public function test_hnd_04_remove_member_missing_ids(): void
    {
        $this->act_as_admin();
        $_POST = ['community_id' => 0, 'user_id' => 0];
        $this->with_nonce('common_goals_remove_member');
        $r = $this->capture_redirect(static fn() => (new CommunitiesAdminPage())->handle_remove_member());
        $this->assertStringContainsString('invalid_member', $r);
    }

    public function test_hnd_05_create_goal_success_and_missing_fields(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $_POST = ['community_id' => $c->id, 'goal_title' => 'G1', 'goal_description' => 'D', 'goal_beneficiary' => '', 'goal_alignment_rules' => '', 'goal_types' => []];
        $this->with_nonce('common_goals_create_goal');
        $r = $this->capture_redirect(static fn() => (new GoalsAdminPage())->handle_create_goal());
        $this->assertStringContainsString('goal_created', $r);

        $_POST = ['community_id' => $c->id, 'goal_title' => '', 'goal_description' => '', 'goal_types' => []];
        $this->with_nonce('common_goals_create_goal');
        $r = $this->capture_redirect(static fn() => (new GoalsAdminPage())->handle_create_goal());
        $this->assertStringContainsString('missing_required_fields', $r);
    }

    public function test_hnd_07_create_guide_success(): void
    {
        $this->act_as_admin();
        $seed = $this->seed();
        $_POST = ['contribution_id' => $seed->contribution_id, 'guide_title' => 'Guide', 'guide_content' => 'Content'];
        $this->with_nonce('common_goals_create_guide');
        $r = $this->capture_redirect(static fn() => (new ContributionsAdminPage())->handle_create_guide());
        $this->assertStringContainsString('guide_created', $r);
        global $wpdb;
        $this->assertNotNull($wpdb->get_var("SELECT id FROM " . Database::guides_table() . " WHERE contribution_id = " . (int) $seed->contribution_id));
    }

    public function test_hnd_08_update_contribution_status_individual(): void
    {
        $this->act_as_admin();
        $seed = $this->seed();
        $this->db()->update(Database::contributions_table(), ['status' => 'pending'], ['id' => $seed->contribution_id]);
        $_POST = ['contribution_id' => $seed->contribution_id, 'contribution_status' => 'open'];
        $this->with_nonce('common_goals_update_contribution_status');
        $r = $this->capture_redirect(static fn() => (new ContributionsAdminPage())->handle_update_contribution_status());
        $this->assertStringContainsString('status_updated', $r);
    }

    public function test_hnd_08_update_contribution_invalid_status(): void
    {
        $this->act_as_admin();
        $seed = $this->seed();
        $_POST = ['contribution_id' => $seed->contribution_id, 'contribution_status' => 'frozen'];
        $this->with_nonce('common_goals_update_contribution_status');
        $r = $this->capture_redirect(static fn() => (new ContributionsAdminPage())->handle_update_contribution_status());
        $this->assertStringContainsString('invalid_status', $r);
    }

    public function test_hnd_09_bulk_moderate_empty(): void
    {
        $this->act_as_admin();
        $_POST = ['contribution_ids' => '', 'bulk_status' => 'open'];
        $this->with_nonce('common_goals_bulk_moderate');
        $r = $this->capture_redirect(static fn() => (new ContributionsAdminPage())->handle_bulk_moderate());
        $this->assertStringContainsString('invalid_bulk', $r);
    }

    public function test_hnd_10_update_response_status(): void
    {
        $this->act_as_admin();
        $seed = $this->seed();
        $this->db()->insert(Database::responses_table(), ['contribution_id' => $seed->contribution_id, 'user_id' => 0, 'body' => 'r', 'status' => 'pending', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')]);
        $resp_id = (int) $this->db()->insert_id;
        $_POST = ['response_id' => $resp_id, 'response_status' => 'published'];
        $this->with_nonce('common_goals_update_response_status');
        $r = $this->capture_redirect(static fn() => (new ContributionsAdminPage())->handle_update_response_status());
        $this->assertStringContainsString('status_updated', $r);
    }

    public function test_hnd_10_response_invalid_status(): void
    {
        $this->act_as_admin();
        $seed = $this->seed();
        $this->db()->insert(Database::responses_table(), ['contribution_id' => $seed->contribution_id, 'user_id' => 0, 'body' => 'r', 'status' => 'pending', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')]);
        $_POST = ['response_id' => $this->db()->insert_id, 'response_status' => 'bogus'];
        $this->with_nonce('common_goals_update_response_status');
        $r = $this->capture_redirect(static fn() => (new ContributionsAdminPage())->handle_update_response_status());
        $this->assertStringContainsString('invalid_status', $r);
    }

    public function test_hnd_11_update_guide(): void
    {
        $this->act_as_admin();
        $seed = $this->seed();
        $this->db()->insert(Database::guides_table(), ['contribution_id' => $seed->contribution_id, 'slug' => 'gslug', 'title' => 'G', 'content' => 'c', 'status' => 'draft', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')]);
        $gid = (int) $this->db()->insert_id;
        $_POST = ['guide_id' => $gid, 'guide_title' => 'Updated', 'guide_content' => 'New', 'guide_slug' => 'gslug', 'guide_status' => 'published'];
        $this->with_nonce('common_goals_update_guide');
        $r = $this->capture_redirect(static fn() => (new GuidesAdminPage())->handle_update_guide());
        $this->assertStringContainsString('guide_updated', $r);
    }

    public function test_hnd_11_update_guide_invalid(): void
    {
        $this->act_as_admin();
        $_POST = ['guide_id' => 0, 'guide_title' => '', 'guide_content' => '', 'guide_slug' => '', 'guide_status' => 'draft'];
        $this->with_nonce('common_goals_update_guide');
        $r = $this->capture_redirect(static fn() => (new GuidesAdminPage())->handle_update_guide());
        $this->assertStringContainsString('invalid_guide', $r);
    }

    public function test_hnd_13_create_contribution_guest_and_user(): void
    {
        $goal = $this->create_goal((int) $this->create_community()->id);
        update_option('common_goals_allow_guest_posting', 1);

        wp_set_current_user(0);
        $_POST = ['goal_id' => $goal->id, 'contribution_type' => 'question', 'contribution_topic' => '', 'contribution_title' => 'Hello', 'contribution_body' => 'World'];
        $this->with_nonce('common_goals_create_contribution');
        $r = $this->capture_redirect(static fn() => (new \CommonGoals\Frontend\BoardShortcode())->handle_create_contribution());
        $this->assertStringContainsString('contribution_pending', $r);

        $this->act_as_subscriber();
        $_POST = ['goal_id' => $goal->id, 'contribution_type' => 'question', 'contribution_topic' => '', 'contribution_title' => 'Auth', 'contribution_body' => 'Post'];
        $this->with_nonce('common_goals_create_contribution');
        $r2 = $this->capture_redirect(static fn() => (new \CommonGoals\Frontend\BoardShortcode())->handle_create_contribution());
        $this->assertStringContainsString('contribution_created', $r2);
    }

    public function test_hnd_13_honeypot_silent_success(): void
    {
        $goal = $this->create_goal((int) $this->create_community()->id);
        wp_set_current_user(0);
        update_option('common_goals_allow_guest_posting', 1);
        update_option('common_goals_honeypot_enabled', 1);
        $_POST = ['goal_id' => $goal->id, 'cg_website' => 'filled', 'contribution_type' => 'question', 'contribution_topic' => '', 'contribution_title' => 'H', 'contribution_body' => 'B'];
        $this->with_nonce('common_goals_create_contribution');
        $r = $this->capture_redirect(static fn() => (new \CommonGoals\Frontend\BoardShortcode())->handle_create_contribution());
        $this->assertStringContainsString('contribution_created', $r);
        global $wpdb;
        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Database::contributions_table() . " WHERE title = 'H'");
        $this->assertSame(0, $pending, 'Honeypot must not create row');
    }

    public function test_hnd_13_guest_posting_disabled(): void
    {
        $goal = $this->create_goal((int) $this->create_community()->id);
        update_option('common_goals_allow_guest_posting', 0);
        wp_set_current_user(0);
        $_POST = ['goal_id' => $goal->id, 'contribution_type' => 'question', 'contribution_title' => 'T', 'contribution_body' => 'B'];
        $this->with_nonce('common_goals_create_contribution');
        $r = $this->capture_redirect(static fn() => (new \CommonGoals\Frontend\BoardShortcode())->handle_create_contribution());
        $this->assertStringContainsString('guest_posting_disabled', $r);
    }

    public function test_hnd_14_create_response(): void
    {
        $seed = $this->seed();
        $this->act_as_subscriber();
        $_POST = ['contribution_id' => $seed->contribution_id, 'response_body' => 'A response'];
        $this->with_nonce('common_goals_create_response');
        $r = $this->capture_redirect(static fn() => (new \CommonGoals\Frontend\BoardShortcode())->handle_create_response());
        $this->assertStringContainsString('response_created', $r);
    }

    public function test_hnd_15_edit_contribution_author(): void
    {
        $goal = $this->create_goal((int) $this->create_community()->id);
        global $wpdb;
        $now = current_time('mysql');
        $uid = $this->act_as_admin_return_id();
        $wpdb->insert(Database::contributions_table(), ['goal_id' => $goal->id, 'user_id' => $uid, 'type' => 'question', 'status' => 'open', 'topic' => '', 'title' => 'orig', 'body' => 'orig', 'created_at' => $now, 'updated_at' => $now]);
        $cid = (int) $wpdb->insert_id;
        $_POST = ['contribution_id' => $cid, 'contribution_title' => 'edited', 'contribution_body' => 'edited'];
        $this->with_nonce('common_goals_edit_contribution');
        $r = $this->capture_redirect(static fn() => (new \CommonGoals\Frontend\BoardShortcode())->handle_edit_contribution());
        $this->assertStringContainsString('contribution_updated', $r);
    }

    public function test_hnd_16_delete_contribution(): void
    {
        $goal = $this->create_goal((int) $this->create_community()->id);
        global $wpdb;
        $now = current_time('mysql');
        $uid = $this->act_as_admin_return_id();
        $wpdb->insert(Database::contributions_table(), ['goal_id' => $goal->id, 'user_id' => $uid, 'type' => 'question', 'status' => 'open', 'topic' => '', 'title' => 'x', 'body' => 'x', 'created_at' => $now, 'updated_at' => $now]);
        $cid = (int) $wpdb->insert_id;
        $wpdb->insert(Database::responses_table(), ['contribution_id' => $cid, 'user_id' => 0, 'body' => 'r', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
        $_POST['contribution_id'] = $cid;
        $this->with_nonce('common_goals_delete_contribution');
        $r = $this->capture_redirect(static fn() => (new \CommonGoals\Frontend\BoardShortcode())->handle_delete_contribution());
        $this->assertStringContainsString('contribution_deleted', $r);
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Database::contributions_table() . " WHERE id = " . $cid));
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Database::responses_table() . " WHERE contribution_id = " . $cid));
    }

    private function act_as_admin_return_id(): int
    {
        $this->act_as_admin();
        return get_current_user_id();
    }

    private function seed(): object
    {
        global $wpdb;
        $community = $this->create_community();
        $goal = $this->create_goal((int) $community->id);
        $wpdb->insert(Database::contributions_table(), ['goal_id' => $goal->id, 'user_id' => get_current_user_id(), 'type' => 'question', 'status' => 'open', 'topic' => '', 'title' => 'C', 'body' => 'b', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')]);
        return (object) ['community_id' => $community->id, 'goal_id' => $goal->id, 'contribution_id' => (int) $wpdb->insert_id];
    }

    private function db(): \wpdb
    {
        global $wpdb;
        return $wpdb;
    }
}
