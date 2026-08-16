<?php
/**
 * Unit tests for Notifications recipient selection and suppression rules.
 *
 * Covers spec case UT-NOT-001.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use Brain\Monkey\Functions;
use CommonGoals\Notifications;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class NotificationsUnitTest extends UnitTestCase
{
    public function test_ut_not_001_register_hooks_registers_three_actions(): void
    {
        Notifications::register_hooks();

        $this->assertNotFalse(has_action('common_goals_contribution_created'));
        $this->assertNotFalse(has_action('common_goals_response_created'));
        $this->assertNotFalse(has_action('common_goals_contribution_status_changed'));
    }

    public function test_ut_not_001_pending_contribution_notifies_each_moderator_once(): void
    {
        $mails = [];
        Functions\when('wp_mail')->alias(static function ($to, $subject, $message) use (&$mails) {
            $mails[] = $to;
        });
        Functions\when('get_users')->justReturn([
            (object) ['user_email' => 'admin@example.test'],
            (object) ['user_email' => 'mod@example.test'],
        ]);
        Functions\when('get_bloginfo')->justReturn('Site');
        $this->wpdb->queue_get_row((object) ['id' => 1, 'title' => 'T', 'type' => 'question', 'topic' => 'x']);

        Notifications::notify_moderators_pending(1, ['status' => 'pending']);

        $this->assertSame(['admin@example.test', 'mod@example.test'], $mails);
    }

    public function test_ut_not_001_non_pending_status_sends_no_mail(): void
    {
        $sent = false;
        Functions\when('wp_mail')->alias(static function () use (&$sent) {
            $sent = true;
        });

        Notifications::notify_moderators_pending(1, ['status' => 'open']);

        $this->assertFalse($sent);
    }

    public function test_ut_not_001_empty_recipients_sends_nothing(): void
    {
        $sent = false;
        Functions\when('wp_mail')->alias(static function () use (&$sent) {
            $sent = true;
        });
        Functions\when('get_users')->justReturn([]);
        $this->wpdb->queue_get_row((object) ['id' => 1, 'title' => 'T', 'type' => 'q', 'topic' => '']);

        Notifications::notify_moderators_pending(1, ['status' => 'pending']);

        $this->assertFalse($sent);
    }

    public function test_ut_not_001_response_published_notifies_contribution_author(): void
    {
        $mails = [];
        Functions\when('wp_mail')->alias(static function ($to) use (&$mails) {
            $mails[] = $to;
        });
        Functions\when('get_userdata')->justReturn((object) ['user_email' => 'author@example.test']);
        Functions\when('wp_trim_words')->returnArg();
        Functions\when('wp_strip_all_tags')->returnArg();
        $this->wpdb->queue_get_row((object) ['id' => 9, 'contribution_id' => 1, 'user_id' => 99, 'body' => 'A response']);
        $this->wpdb->queue_get_row((object) ['id' => 1, 'user_id' => 7, 'title' => 'T']);

        Notifications::notify_contribution_author(9, ['status' => 'published']);

        $this->assertSame(['author@example.test'], $mails);
    }

    public function test_ut_not_001_response_published_skips_when_author_is_responder(): void
    {
        $sent = false;
        Functions\when('wp_mail')->alias(static function () use (&$sent) {
            $sent = true;
        });
        $this->wpdb->queue_get_row((object) ['id' => 9, 'contribution_id' => 1, 'user_id' => 7, 'body' => 'x']);
        $this->wpdb->queue_get_row((object) ['id' => 1, 'user_id' => 7, 'title' => 'T']);

        Notifications::notify_contribution_author(9, ['status' => 'published']);

        $this->assertFalse($sent, 'No mail when the responder is the contribution author');
    }

    public function test_ut_not_001_approved_only_fires_for_pending_to_public_transition(): void
    {
        $sent = false;
        Functions\when('wp_mail')->alias(static function () use (&$sent) {
            $sent = true;
        });

        Notifications::notify_author_approved(1, 'open', 'resolved');

        $this->assertFalse($sent, 'Approval mail must only fire when old status is pending');
    }

    public function test_ut_not_001_approval_notifies_author_for_pending_to_open(): void
    {
        $mails = [];
        Functions\when('wp_mail')->alias(static function ($to) use (&$mails) {
            $mails[] = $to;
        });
        Functions\when('get_userdata')->justReturn((object) ['user_email' => 'creator@example.test']);
        $this->wpdb->queue_get_row((object) ['id' => 1, 'user_id' => 7, 'title' => 'T']);

        Notifications::notify_author_approved(1, 'pending', 'open');

        $this->assertSame(['creator@example.test'], $mails);
    }
}
