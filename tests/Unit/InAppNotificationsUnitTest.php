<?php
/**
 * Unit tests for in-app notifications and @mentions.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use Brain\Monkey\Functions;
use CommonGoals\Database;
use CommonGoals\InAppNotifications;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class InAppNotificationsUnitTest extends UnitTestCase
{
    public function test_extract_mentions_resolves_unique_users_by_slug_or_login(): void
    {
        Functions\when('get_user_by')->alias(static function ($field, $value) {
            if ($field === 'slug' && $value === 'mention-alpha') {
                return (object) ['ID' => 21];
            }

            if ($field === 'login' && $value === 'login-beta') {
                return (object) ['ID' => 22];
            }

            return false;
        });

        $ids = InAppNotifications::extract_mentions('@mention-alpha and @mention-alpha plus @login-beta and @missing');

        $this->assertSame([21, 22], $ids);
    }

    public function test_link_mentions_converts_known_mentions_without_touching_unknowns_or_email_addresses(): void
    {
        Functions\when('get_user_by')->alias(static function ($field, $value) {
            return $value === 'profile-gamma' ? (object) ['ID' => 33] : false;
        });

        $html = InAppNotifications::link_mentions('Hello @profile-gamma, @unknown and test@example.test');

        $this->assertStringContainsString('<a href="https://example.test/autor/33/" class="common-goals-mention">@profile-gamma</a>', $html);
        $this->assertStringContainsString('@unknown', $html);
        $this->assertStringContainsString('test@example.test', $html);
    }

    public function test_create_rejects_invalid_user_and_inserts_valid_notification(): void
    {
        $this->assertSame(0, InAppNotifications::create(0, 'mention', 'contribution', 1, 2, 'Nope'));

        $this->assertSame(41, InAppNotifications::create(9, 'mention', 'contribution', 3, 4, 'Summary'));

        $insert = $this->wpdb->calls[0];
        $this->assertSame('insert', $insert['method']);
        $this->assertSame(Database::notifications_table(), $insert['sql']);
        $this->assertSame([
            'user_id'     => 9,
            'type'        => 'mention',
            'object_type' => 'contribution',
            'object_id'   => 3,
            'actor_id'    => 4,
            'summary'     => 'Summary',
            'is_read'     => 0,
            'created_at'  => '2026-07-26 12:00:00',
        ], $insert['extra']['data']);
    }

    public function test_unread_count_for_user_and_invalid_user(): void
    {
        $this->assertSame(0, InAppNotifications::unread_count(0));

        $this->wpdb->queue_get_var('6');

        $this->assertSame(6, InAppNotifications::unread_count(9));
        $this->assertSqlContainsInOneCall(['wp_cg_notifications', 'user_id = 9', 'is_read = 0']);
    }

    public function test_for_user_limits_results_and_invalid_user_returns_empty(): void
    {
        $rows = [(object) ['id' => 1], (object) ['id' => 2]];
        $this->wpdb->queue_get_results($rows);

        $this->assertSame([], InAppNotifications::for_user(0));
        $this->assertSame($rows, InAppNotifications::for_user(9, 2));
        $this->assertSqlContainsInOneCall(['wp_cg_notifications', 'user_id = 9', 'LIMIT 2']);
    }

    public function test_mark_read_and_mark_all_read_scope_updates_to_owner(): void
    {
        InAppNotifications::mark_read(5, 9);
        InAppNotifications::mark_all_read(9);

        $this->assertSame(2, $this->wpdb->count_method('update'));
        $this->assertSame(['id' => 5, 'user_id' => 9], $this->wpdb->calls[0]['extra']['where']);
        $this->assertSame(['user_id' => 9], $this->wpdb->calls[1]['extra']['where']);
    }

    public function test_on_response_created_notifies_thread_author_parent_author_and_mentioned_user_once(): void
    {
        Functions\when('get_userdata')->alias(static fn($id) => (object) ['display_name' => 'Actor ' . $id]);
        Functions\when('get_user_by')->alias(static function ($field, $value) {
            return $value === 'mention-delta' ? (object) ['ID' => 12] : false;
        });

        $this->wpdb->queue_get_row((object) [
            'id'              => 99,
            'contribution_id' => 44,
            'user_id'         => 7,
            'parent_id'       => 55,
            'body'            => 'Hi @mention-delta and @mention-delta',
        ]);
        $this->wpdb->queue_get_row((object) ['id' => 44, 'user_id' => 10, 'title' => 'Thread title']);
        $this->wpdb->queue_get_row((object) ['user_id' => 11]);

        InAppNotifications::on_response_created(99, ['status' => 'published']);

        $this->assertSame(3, $this->wpdb->count_method('insert'));
        $recipients = array_map(static fn($call) => $call['extra']['data']['user_id'], array_filter($this->wpdb->calls, static fn($call) => $call['method'] === 'insert'));
        $this->assertSame([10, 11, 12], array_values($recipients));
    }

    public function test_on_response_created_ignores_non_published_responses(): void
    {
        InAppNotifications::on_response_created(99, ['status' => 'pending']);

        $this->assertSame(0, $this->wpdb->count_method('get_row'));
        $this->assertSame(0, $this->wpdb->count_method('insert'));
    }

    public function test_on_contribution_created_notifies_mentions_for_public_threads_only(): void
    {
        Functions\when('get_userdata')->alias(static fn($id) => (object) ['display_name' => 'Author']);
        Functions\when('get_user_by')->alias(static function ($field, $value) {
            return $value === 'mention-epsilon' ? (object) ['ID' => 13] : false;
        });

        InAppNotifications::on_contribution_created(44, ['status' => 'pending']);
        $this->assertSame(0, $this->wpdb->count_method('get_row'));

        $this->wpdb->queue_get_row((object) ['id' => 44, 'user_id' => 7, 'title' => 'Thread', 'body' => 'Hello @mention-epsilon']);
        InAppNotifications::on_contribution_created(44, ['status' => 'open']);

        $this->assertSame(1, $this->wpdb->count_method('insert'));
        $this->assertSame(13, $this->wpdb->calls[1]['extra']['data']['user_id']);
    }
}
