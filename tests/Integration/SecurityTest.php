<?php

namespace CommonGoals\Tests\Integration;

use CommonGoals\Admin\CommunitiesAdminPage;
use CommonGoals\Admin\GoalsAdminPage;
use CommonGoals\Capabilities;
use CommonGoals\Database;
use CommonGoals\Domain;
use CommonGoals\Tests\Integration\Support\IntegrationTestCase;

final class SecurityTest extends IntegrationTestCase
{
    public function test_sec_001_subscriber_cannot_create_community(): void
    {
        $this->act_as_subscriber();
        $this->assertFalse(current_user_can(Capabilities::MANAGE));

        $_POST = ['community_name' => 'HaX', 'community_description' => ''];
        $this->with_nonce('common_goals_create_community');

        if (class_exists(\WPDieException::class)) {
            try {
                (new CommunitiesAdminPage())->handle_create_community();
                $this->fail('Expected wp_die for subscriber without MANAGE capability');
            } catch (\WPDieException $e) {
                $this->addToAssertionCount(1);
            }
        } else {
            $r = $this->capture_redirect(static fn() => (new CommunitiesAdminPage())->handle_create_community());
            if ($r !== null) {
                $this->assertStringNotContainsString('community_created', $r);
            }
        }
    }

    public function test_sec_002_global_admin_has_manage_capability(): void
    {
        $this->act_as_admin();
        $this->assertTrue(current_user_can(Capabilities::MANAGE));
    }

    public function test_sec_003_subscriber_lacks_all_plugin_capabilities(): void
    {
        $this->act_as_subscriber();
        $this->assertFalse(current_user_can(Capabilities::MANAGE));
        $this->assertFalse(current_user_can(Capabilities::MODERATE));
        $this->assertFalse(current_user_can(Capabilities::PUBLISH_GUIDES));
        $this->assertFalse(current_user_can(Capabilities::VIEW_EVENTS));
    }

    public function test_sec_004_cross_community_isolation(): void
    {
        $this->act_as_admin();
        $community_a = $this->create_community(['name' => 'Alpha', 'slug' => 'alpha']);
        $community_b = $this->create_community(['name' => 'Bravo', 'slug' => 'bravo']);

        $uid = wp_insert_user([
            'user_login' => 'crossuser_' . uniqid(),
            'user_email' => 'crossuser_' . uniqid() . '@cg-test.test',
            'user_pass' => 'x',
            'role' => 'subscriber',
        ]);

        global $wpdb;
        $wpdb->insert(Database::community_members_table(), [
            'community_id' => $community_a->id,
            'user_id' => $uid,
            'role' => 'admin',
            'created_at' => current_time('mysql'),
        ]);

        $this->create_goal((int) $community_b->id, ['title' => 'Goal in B']);

        $this->assertSame('admin', Domain::get_user_community_role($uid, (int) $community_a->id));
        $this->assertNull(Domain::get_user_community_role($uid, (int) $community_b->id));
    }

    public function test_sec_005_csrf_handler_without_nonce_redirects_to_denial(): void
    {
        $this->act_as_admin();
        $_POST = ['community_name' => 'NoNonce', 'community_description' => ''];

        if (class_exists(\WPDieException::class)) {
            try {
                (new CommunitiesAdminPage())->handle_create_community();
                $this->fail('Expected wp_die for missing nonce');
            } catch (\WPDieException $e) {
                $this->addToAssertionCount(1);
            }
        } else {
            $r = $this->capture_redirect(static fn() => (new CommunitiesAdminPage())->handle_create_community());
            if ($r !== null) {
                $this->assertStringNotContainsString('community_created', $r);
            }
        }
    }

    public function test_sec_006_wrong_action_nonce_rejected(): void
    {
        $this->act_as_admin();
        $_POST = ['community_name' => 'WrongNonce', 'community_description' => ''];
        $this->with_nonce('common_goals_create_goal');

        if (class_exists(\WPDieException::class)) {
            try {
                (new CommunitiesAdminPage())->handle_create_community();
                $this->fail('Expected wp_die for wrong nonce action');
            } catch (\WPDieException $e) {
                $this->addToAssertionCount(1);
            }
        } else {
            $r = $this->capture_redirect(static fn() => (new CommunitiesAdminPage())->handle_create_community());
            if ($r !== null) {
                $this->assertStringNotContainsString('community_created', $r);
            }
        }
    }

    public function test_sec_007_xss_script_tags_in_community_name_stripped(): void
    {
        $this->act_as_admin();
        $_POST = [
            'community_name' => '<script>alert(1)</script>Clean',
            'community_description' => '',
        ];
        $this->with_nonce('common_goals_create_community');

        $r = $this->capture_redirect(static fn() => (new CommunitiesAdminPage())->handle_create_community());
        $this->assertStringContainsString('community_created', $r);

        global $wpdb;
        $name = $wpdb->get_var("SELECT name FROM " . Database::communities_table() . " WHERE slug = 'clean'");
        $this->assertStringNotContainsString('<script>', $name);
        $this->assertStringContainsString('Clean', $name);
    }

    public function test_sec_008_xss_html_preserved_script_stripped_in_contribution(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);

        $_POST = [
            'goal_id' => $g->id,
            'contribution_type' => 'question',
            'contribution_topic' => '',
            'contribution_title' => 'Safe',
            'contribution_body' => '<b>ok</b><script>x</script>',
        ];
        $this->with_nonce('common_goals_create_contribution');

        $r = $this->capture_redirect(static fn() => (new \CommonGoals\Frontend\BoardShortcode())->handle_create_contribution());
        $this->assertStringContainsString('contribution_created', $r);

        global $wpdb;
        $body = $wpdb->get_var("SELECT body FROM " . Database::contributions_table() . " WHERE title = 'Safe'");
        $this->assertStringContainsString('<b>ok</b>', $body);
        $this->assertStringNotContainsString('<script>', $body);
    }

    public function test_sec_009_sqli_numeric_ids_cast_to_int(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();

        $_POST = [
            'community_id' => $c->id . ' OR 1=1',
            'goal_title' => 'Safe Goal',
            'goal_description' => 'desc',
            'goal_beneficiary' => '',
            'goal_alignment_rules' => '',
            'goal_types' => [],
        ];
        $this->with_nonce('common_goals_create_goal');

        $r = $this->capture_redirect(static fn() => (new GoalsAdminPage())->handle_create_goal());
        $this->assertStringContainsString('goal_created', $r);
    }

    public function test_sec_010_sqli_search_parameter_uses_prepare(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $this->create_goal((int) $c->id);

        $request = new \WP_REST_Request('GET', '/common-goals/v1/contributions');
        $request->set_query_params([
            'type' => "Test'; DROP TABLE--",
        ]);

        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());
    }

    public function test_sec_011_guest_posting_disabled_rest_returns_403(): void
    {
        update_option('common_goals_allow_guest_posting', 0);
        wp_set_current_user(0);

        $goal = $this->create_goal((int) $this->create_community()->id);

        $request = new \WP_REST_Request('POST', '/common-goals/v1/contributions');
        $request->set_body_params([
            'goal_id' => $goal->id,
            'type' => 'question',
            'title' => 'Blocked',
            'body' => 'Blocked',
        ]);

        $response = rest_do_request($request);
        $this->assertContains($response->get_status(), [401, 403]);
    }

    public function test_sec_012_rate_limiting_transient_check(): void
    {
        $this->act_as_subscriber();
        $uid = get_current_user_id();
        $key = 'cg_rate_contribution_u' . $uid;
        set_transient($key, 99, Domain::RATE_LIMIT_WINDOW);

        $this->assertFalse(Domain::check_rate_limit('contribution'));
    }

    public function test_sec_013_status_transition_validation(): void
    {
        $this->assertTrue(Domain::is_valid_transition('pending', 'open'));
        $this->assertTrue(Domain::is_valid_transition('open', 'resolved'));
        $this->assertFalse(Domain::is_valid_transition('open', 'bogus'));
        $this->assertTrue(Domain::is_valid_transition('spam', 'open'));
        $this->assertFalse(Domain::is_valid_transition('spam', 'bogus'));
    }

    public function test_sec_014_honeypot_enabled_and_disabled(): void
    {
        update_option('common_goals_honeypot_enabled', 1);
        $_POST['cg_website'] = '';
        $this->assertFalse(Domain::honeypot_triggered());

        $_POST['cg_website'] = 'filled';
        $this->assertTrue(Domain::honeypot_triggered());

        update_option('common_goals_honeypot_enabled', 0);
        $_POST['cg_website'] = 'filled';
        $this->assertFalse(Domain::honeypot_triggered());

        $_POST['cg_website'] = '';
        $this->assertFalse(Domain::honeypot_triggered());
    }

    public function test_sec_015_spam_detection_excessive_links(): void
    {
        $this->assertFalse(Domain::is_spam('hello', 'contribution'));
        $this->assertTrue(Domain::is_spam('https://a https://b https://c https://d https://e', 'contribution'));
        $this->assertFalse(Domain::is_spam('https://a https://b https://c https://d', 'contribution'));
    }

    public function test_sec_016_public_statuses_filter(): void
    {
        $this->assertContains('open', Domain::PUBLIC_STATUSES);
        $this->assertNotContains('pending', Domain::PUBLIC_STATUSES);
        $this->assertNotContains('spam', Domain::PUBLIC_STATUSES);
    }

    public function test_sec_017_visible_contribution_hides_spam(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);

        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert(Database::contributions_table(), [
            'goal_id' => $g->id,
            'user_id' => get_current_user_id(),
            'type' => 'question',
            'status' => 'spam',
            'topic' => '',
            'title' => 'Spam Entry',
            'body' => 'spam body',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $wpdb->insert_id;

        $this->assertNull(Domain::get_visible_contribution($id));
        $this->assertNotNull(Domain::get_contribution($id));
    }
}
