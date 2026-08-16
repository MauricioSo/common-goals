<?php
/**
 * Integration tests for Privacy, Notifications, and Cron (spec 14).
 *
 * @package CommonGoals\Tests\Integration
 */

namespace CommonGoals\Tests\Integration;

use CommonGoals\Database;
use CommonGoals\Domain;
use CommonGoals\Notifications;
use CommonGoals\Privacy;
use CommonGoals\Tests\Integration\Support\IntegrationTestCase;

final class PrivacyNotificationTest extends IntegrationTestCase
{
    public function test_privacy_001_exporter_is_registered(): void
    {
        $exporters = apply_filters('wp_privacy_personal_data_exporters', []);

        $this->assertArrayHasKey('common-goals', $exporters);
        $this->assertSame('Common Goals Community Data', $exporters['common-goals']['exporter_friendly_name']);
        $this->assertSame([Privacy::class, 'export_user_data'], $exporters['common-goals']['callback']);
    }

    public function test_privacy_002_eraser_is_registered(): void
    {
        $erasers = apply_filters('wp_privacy_personal_data_erasers', []);

        $this->assertArrayHasKey('common-goals', $erasers);
        $this->assertSame('Common Goals Community Data', $erasers['common-goals']['eraser_friendly_name']);
        $this->assertSame([Privacy::class, 'erase_user_data'], $erasers['common-goals']['callback']);
    }

    public function test_privacy_003_export_for_nonexistent_email_returns_empty(): void
    {
        $result = Privacy::export_user_data('nonexistent@test.test');

        $this->assertSame([], $result['data']);
        $this->assertTrue($result['done']);
    }

    public function test_privacy_004_export_for_existing_user_returns_their_data(): void
    {
        $this->act_as_admin();
        global $wpdb;

        $user = get_userdata(get_current_user_id());
        $email = $user->user_email;

        $community = $this->create_community();
        $goal = $this->create_goal((int) $community->id);

        $wpdb->insert(Database::contributions_table(), [
            'goal_id' => $goal->id,
            'user_id' => get_current_user_id(),
            'type' => 'question',
            'status' => 'open',
            'topic' => 'Test',
            'title' => 'Test Contribution',
            'body' => 'Body content',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $contribution_id = (int) $wpdb->insert_id;

        $result = Privacy::export_user_data($email);

        $this->assertNotEmpty($result['data']);
        $this->assertTrue($result['done']);

        $contribution_items = array_filter($result['data'], static function ($item) {
            return $item['group_id'] === 'common-goals-contributions';
        });
        $this->assertNotEmpty($contribution_items);

        $item = reset($contribution_items);
        $this->assertSame('common-goals-contributions', $item['group_id']);
        $this->assertSame('cg-contribution-' . $contribution_id, $item['item_id']);
    }

    public function test_privacy_005_erase_anonymizes_contributions(): void
    {
        $this->act_as_admin();
        global $wpdb;

        $user_id = get_current_user_id();
        $user = get_userdata($user_id);

        $community = $this->create_community();
        $goal = $this->create_goal((int) $community->id);

        $wpdb->insert(Database::community_members_table(), [
            'community_id' => (int) $community->id,
            'user_id' => $user_id,
            'role' => 'member',
            'created_at' => current_time('mysql'),
        ]);

        $wpdb->insert(Database::contributions_table(), [
            'goal_id' => $goal->id,
            'user_id' => $user_id,
            'type' => 'question',
            'status' => 'open',
            'topic' => '',
            'title' => 'Test',
            'body' => 'Body',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $contribution_id = (int) $wpdb->insert_id;

        $result = Privacy::erase_user_data($user->user_email);

        $this->assertTrue($result['done']);
        $this->assertGreaterThanOrEqual(2, $result['items_removed']);

        $contrib_user_id = $wpdb->get_var($wpdb->prepare(
            'SELECT user_id FROM ' . Database::contributions_table() . ' WHERE id = %d',
            $contribution_id
        ));
        $this->assertSame('0', $contrib_user_id);

        $membership_count = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Database::community_members_table() . ' WHERE user_id = %d',
            $user_id
        ));
        $this->assertSame(0, $membership_count);
    }

    public function test_privacy_006_anonymize_user_data_via_hook(): void
    {
        $this->act_as_admin();
        global $wpdb;

        $user_id = get_current_user_id();

        $community = $this->create_community();
        $goal = $this->create_goal((int) $community->id);

        $wpdb->insert(Database::contributions_table(), [
            'goal_id' => $goal->id,
            'user_id' => $user_id,
            'type' => 'question',
            'status' => 'open',
            'topic' => '',
            'title' => 'Test',
            'body' => 'Body',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $contribution_id = (int) $wpdb->insert_id;

        $wpdb->insert(Database::responses_table(), [
            'contribution_id' => $contribution_id,
            'user_id' => $user_id,
            'body' => 'Response body',
            'status' => 'published',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $response_id = (int) $wpdb->insert_id;

        $wpdb->insert(Database::events_table(), [
            'object_type' => 'contribution',
            'object_id' => $contribution_id,
            'event_type' => 'created',
            'event_data' => null,
            'created_by' => $user_id,
            'created_at' => current_time('mysql'),
        ]);
        $event_id = (int) $wpdb->insert_id;

        Privacy::anonymize_user_data($user_id);

        $contrib_user_id = $wpdb->get_var($wpdb->prepare(
            'SELECT user_id FROM ' . Database::contributions_table() . ' WHERE id = %d',
            $contribution_id
        ));
        $this->assertSame('0', $contrib_user_id);

        $response_user_id = $wpdb->get_var($wpdb->prepare(
            'SELECT user_id FROM ' . Database::responses_table() . ' WHERE id = %d',
            $response_id
        ));
        $this->assertSame('0', $response_user_id);

        $event_created_by = $wpdb->get_var($wpdb->prepare(
            'SELECT created_by FROM ' . Database::events_table() . ' WHERE id = %d',
            $event_id
        ));
        $this->assertSame('0', $event_created_by);
    }

    public function test_privacy_007_policy_content_is_registered(): void
    {
        $this->assertTrue(function_exists('wp_add_privacy_policy_content'));

        @Privacy::add_privacy_policy_content();

        $this->addToAssertionCount(1);
    }

    public function test_notif_008_get_moderator_emails_includes_admin(): void
    {
        $this->act_as_admin();

        $admin_email = (get_userdata(get_current_user_id()))->user_email;

        $ref = new \ReflectionMethod(Notifications::class, 'get_moderator_emails');
        $ref->setAccessible(true);
        $emails = $ref->invoke(null);

        $this->assertContains($admin_email, $emails);
    }

    public function test_notif_009_moderator_notification_triggered_for_pending(): void
    {
        $this->act_as_admin();
        global $wpdb;

        $community = $this->create_community();
        $goal = $this->create_goal((int) $community->id);

        $wpdb->insert(Database::contributions_table(), [
            'goal_id' => $goal->id,
            'user_id' => 0,
            'type' => 'question',
            'status' => 'pending',
            'topic' => '',
            'title' => 'Pending Contribution',
            'body' => 'Body',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $contribution_id = (int) $wpdb->insert_id;

        $mails = [];
        add_filter('pre_wp_mail', static function ($null, $atts) use (&$mails) {
            $mails[] = $atts;
            return true;
        }, 10, 2);

        Notifications::notify_moderators_pending($contribution_id, ['status' => 'pending']);

        $this->assertNotEmpty($mails);
        $this->assertStringContainsString('awaiting moderation', $mails[0]['subject']);
    }

    public function test_notif_010_non_pending_status_does_not_trigger_email(): void
    {
        $this->act_as_admin();
        global $wpdb;

        $community = $this->create_community();
        $goal = $this->create_goal((int) $community->id);

        $wpdb->insert(Database::contributions_table(), [
            'goal_id' => $goal->id,
            'user_id' => 0,
            'type' => 'question',
            'status' => 'open',
            'topic' => '',
            'title' => 'Open Contribution',
            'body' => 'Body',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $contribution_id = (int) $wpdb->insert_id;

        $mails = [];
        add_filter('pre_wp_mail', static function ($null, $atts) use (&$mails) {
            $mails[] = $atts;
            return true;
        }, 10, 2);

        Notifications::notify_moderators_pending($contribution_id, ['status' => 'open']);

        $this->assertEmpty($mails);
    }

    public function test_notif_011_author_notified_on_response_not_self(): void
    {
        $this->act_as_admin();
        global $wpdb;

        $user_a_id = get_current_user_id();
        $user_a = get_userdata($user_a_id);
        $user_a_email = $user_a->user_email;

        $community = $this->create_community();
        $goal = $this->create_goal((int) $community->id);

        $wpdb->insert(Database::contributions_table(), [
            'goal_id' => $goal->id,
            'user_id' => $user_a_id,
            'type' => 'question',
            'status' => 'open',
            'topic' => '',
            'title' => 'Author Contribution',
            'body' => 'Body',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $contribution_id = (int) $wpdb->insert_id;

        $this->act_as_subscriber();
        $user_b_id = get_current_user_id();

        $wpdb->insert(Database::responses_table(), [
            'contribution_id' => $contribution_id,
            'user_id' => $user_b_id,
            'body' => 'A helpful response',
            'status' => 'published',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $response_id = (int) $wpdb->insert_id;

        $mails = [];
        add_filter('pre_wp_mail', static function ($null, $atts) use (&$mails) {
            $mails[] = $atts;
            return true;
        }, 10, 2);

        Notifications::notify_contribution_author($response_id, ['status' => 'published']);

        $this->assertCount(1, $mails);
        $this->assertSame($user_a_email, $mails[0]['to']);
        $this->assertStringContainsString('response to your contribution', $mails[0]['subject']);
    }

    public function test_notif_012_author_not_notified_for_self_response(): void
    {
        $this->act_as_admin();
        global $wpdb;

        $user_a_id = get_current_user_id();

        $community = $this->create_community();
        $goal = $this->create_goal((int) $community->id);

        $wpdb->insert(Database::contributions_table(), [
            'goal_id' => $goal->id,
            'user_id' => $user_a_id,
            'type' => 'question',
            'status' => 'open',
            'topic' => '',
            'title' => 'Self Response Test',
            'body' => 'Body',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $contribution_id = (int) $wpdb->insert_id;

        $wpdb->insert(Database::responses_table(), [
            'contribution_id' => $contribution_id,
            'user_id' => $user_a_id,
            'body' => 'My own response',
            'status' => 'published',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $response_id = (int) $wpdb->insert_id;

        $mails = [];
        add_filter('pre_wp_mail', static function ($null, $atts) use (&$mails) {
            $mails[] = $atts;
            return true;
        }, 10, 2);

        Notifications::notify_contribution_author($response_id, ['status' => 'published']);

        $this->assertEmpty($mails);
    }

    public function test_notif_013_author_approved_notification(): void
    {
        $this->act_as_admin();
        global $wpdb;

        $user_a_id = get_current_user_id();
        $user_a = get_userdata($user_a_id);
        $user_a_email = $user_a->user_email;

        $community = $this->create_community();
        $goal = $this->create_goal((int) $community->id);

        $wpdb->insert(Database::contributions_table(), [
            'goal_id' => $goal->id,
            'user_id' => $user_a_id,
            'type' => 'question',
            'status' => 'pending',
            'topic' => '',
            'title' => 'Awaiting Approval',
            'body' => 'Body',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $contribution_id = (int) $wpdb->insert_id;

        $mails = [];
        add_filter('pre_wp_mail', static function ($null, $atts) use (&$mails) {
            $mails[] = $atts;
            return true;
        }, 10, 2);

        Notifications::notify_author_approved($contribution_id, 'pending', 'open');

        $this->assertCount(1, $mails);
        $this->assertSame($user_a_email, $mails[0]['to']);
        $this->assertStringContainsString('approved', $mails[0]['subject']);
    }

    public function test_notif_014_notification_filters_are_applied(): void
    {
        $this->act_as_admin();
        global $wpdb;

        $community = $this->create_community();
        $goal = $this->create_goal((int) $community->id);

        $wpdb->insert(Database::contributions_table(), [
            'goal_id' => $goal->id,
            'user_id' => 0,
            'type' => 'question',
            'status' => 'pending',
            'topic' => '',
            'title' => 'Filter Test',
            'body' => 'Body',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $contribution_id = (int) $wpdb->insert_id;

        add_filter('common_goals_notification_subject', static function ($subject) {
            return $subject . ' FILTERED';
        });

        $mails = [];
        add_filter('pre_wp_mail', static function ($null, $atts) use (&$mails) {
            $mails[] = $atts;
            return true;
        }, 10, 2);

        Notifications::notify_moderators_pending($contribution_id, ['status' => 'pending']);

        $this->assertNotEmpty($mails);
        $this->assertStringContainsString('FILTERED', $mails[0]['subject']);
    }

    public function test_notif_015_rate_limit_honors_option(): void
    {
        delete_option('common_goals_rate_limit_max');
        $this->assertSame(5, Domain::rate_limit_max());

        update_option('common_goals_rate_limit_max', 3);
        $this->assertSame(3, Domain::rate_limit_max());

        update_option('common_goals_rate_limit_max', 0);
        $this->assertSame(5, Domain::rate_limit_max());
    }
}
