<?php
/**
 * Unit tests for forum-domain behaviours: votes, sticky state, bookmarks and reports.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use Brain\Monkey\Functions;
use CommonGoals\Database;
use CommonGoals\Domain;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class DomainForumUnitTest extends UnitTestCase
{
    public function test_format_count_uses_compact_suffixes(): void
    {
        $this->assertSame('999', Domain::format_count(999));
        $this->assertSame('1.2k', Domain::format_count(1234));
        $this->assertSame('2.5M', Domain::format_count(2500000));
    }

    public function test_set_sticky_updates_contribution_and_logs_event(): void
    {
        $this->assertTrue(Domain::set_sticky(12, true));

        $updates = array_values(array_filter($this->wpdb->calls, static fn($call) => $call['method'] === 'update'));
        $this->assertSame(Database::contributions_table(), $updates[0]['sql']);
        $this->assertSame(['is_sticky' => 1, 'updated_at' => '2026-07-26 12:00:00'], $updates[0]['extra']['data']);
        $this->assertSame(['id' => 12], $updates[0]['extra']['where']);
        $this->assertSame(1, $this->wpdb->count_method('insert'), 'Sticky changes must be event logged.');
    }

    public function test_set_sticky_rejects_invalid_ids_and_failed_updates(): void
    {
        $this->assertFalse(Domain::set_sticky(0, true));

        $this->wpdb->queue_update(false);

        $this->assertFalse(Domain::set_sticky(9, false));
    }

    public function test_create_report_requires_logged_in_user_valid_target_and_reason(): void
    {
        $this->assertSame(0, Domain::create_report('contribution', 1, 'spam', 'detail'));

        Functions\when('get_current_user_id')->justReturn(5);

        $this->assertSame(0, Domain::create_report('bad', 1, 'spam', 'detail'));
        $this->assertSame(0, Domain::create_report('contribution', 0, 'spam', 'detail'));
        $this->assertSame(0, Domain::create_report('contribution', 1, 'invalid', 'detail'));
    }

    public function test_create_report_inserts_pending_report(): void
    {
        Functions\when('get_current_user_id')->justReturn(5);

        $this->assertSame(41, Domain::create_report('response', 9, 'harassment', 'Too much'));

        $insert = $this->wpdb->calls[0];
        $this->assertSame('insert', $insert['method']);
        $this->assertSame(Database::reports_table(), $insert['sql']);
        $this->assertSame([
            'object_type' => 'response',
            'object_id'   => 9,
            'reporter_id' => 5,
            'reason'      => 'harassment',
            'detail'      => 'Too much',
            'status'      => 'pending',
            'created_at'  => '2026-07-26 12:00:00',
        ], $insert['extra']['data']);
    }

    public function test_cast_vote_inserts_new_vote_updates_score_and_logs_event(): void
    {
        Functions\when('get_current_user_id')->justReturn(7);
        $this->wpdb->queue_get_row(null);
        $this->wpdb->queue_get_var('3');

        $result = Domain::cast_vote('contribution', 44, 1);

        $this->assertSame(['score' => 3, 'user_vote' => 1], $result);
        $this->assertSqlContainsInOneCall('START TRANSACTION');
        $this->assertSqlContainsInOneCall(['UPDATE wp_cg_contributions', 'score = score + 1', 'id = 44']);
        $this->assertSqlContainsInOneCall('COMMIT');
        $this->assertSame(Database::votes_table(), $this->wpdb->calls[2]['sql']);
        $this->assertSame(2, $this->wpdb->count_method('insert'), 'Vote insert plus event log insert expected.');
    }

    public function test_cast_vote_toggles_same_vote_off(): void
    {
        Functions\when('get_current_user_id')->justReturn(7);
        $this->wpdb->queue_get_row((object) ['id' => 99, 'value' => 1]);
        $this->wpdb->queue_get_var('2');

        $result = Domain::cast_vote('contribution', 44, 1);

        $this->assertSame(['score' => 2, 'user_vote' => 0], $result);
        $this->assertSame(1, $this->wpdb->count_method('delete'));
        $this->assertSqlContainsInOneCall(['UPDATE wp_cg_contributions', 'score = score - 1', 'id = 44']);
    }

    public function test_cast_vote_flips_opposite_vote_by_two_points(): void
    {
        Functions\when('get_current_user_id')->justReturn(7);
        $this->wpdb->queue_get_row((object) ['id' => 99, 'value' => -1]);
        $this->wpdb->queue_get_var('4');

        $result = Domain::cast_vote('response', 55, 1);

        $this->assertSame(['score' => 4, 'user_vote' => 1], $result);
        $this->assertSame(1, $this->wpdb->count_method('update'));
        $this->assertSqlContainsInOneCall(['UPDATE wp_cg_responses', 'score = score + 2', 'id = 55']);
    }

    public function test_cast_vote_rejects_guests_invalid_types_and_invalid_ids(): void
    {
        $this->assertSame(['score' => 0, 'user_vote' => 0], Domain::cast_vote('contribution', 1, 1));

        Functions\when('get_current_user_id')->justReturn(7);

        $this->assertSame(['score' => 0, 'user_vote' => 0], Domain::cast_vote('bad', 1, 1));
        $this->assertSame(['score' => 0, 'user_vote' => 0], Domain::cast_vote('contribution', 0, 1));
    }

    public function test_get_user_votes_returns_map_for_current_user_only(): void
    {
        Functions\when('get_current_user_id')->justReturn(7);
        $this->wpdb->queue_get_results([
            (object) ['object_id' => 1, 'value' => 1],
            (object) ['object_id' => 2, 'value' => -1],
        ]);

        $this->assertSame([1 => 1, 2 => -1], Domain::get_user_votes('contribution', [1, 2, 0]));
        $this->assertSqlContainsInOneCall(['wp_cg_votes', "object_type = 'contribution'", 'object_id IN (1,2)']);
    }

    public function test_toggle_bookmark_deletes_existing_or_inserts_new(): void
    {
        Functions\when('get_current_user_id')->justReturn(7);

        $this->wpdb->queue_get_var('22');
        $this->assertFalse(Domain::toggle_bookmark(9));
        $this->assertSame(1, $this->wpdb->count_method('delete'));

        $this->wpdb->queue_get_var(null);
        $this->assertTrue(Domain::toggle_bookmark(10));

        $last = array_slice(array_filter($this->wpdb->calls, static fn($call) => $call['method'] === 'insert'), -1)[0];
        $this->assertSame(Database::bookmarks_table(), $last['sql']);
        $this->assertSame(['user_id' => 7, 'contribution_id' => 10, 'created_at' => '2026-07-26 12:00:00'], $last['extra']['data']);
    }

    public function test_get_bookmarked_ids_supports_empty_and_restricted_queries(): void
    {
        Functions\when('get_current_user_id')->justReturn(7);
        $this->wpdb->queue_get_col(['5', '4']);
        $this->wpdb->queue_get_col(['8']);

        $this->assertSame([5, 4], Domain::get_bookmarked_ids());
        $this->assertSame([8], Domain::get_bookmarked_ids([8, 0, 9]));
        $this->assertSqlContainsInOneCall('ORDER BY id DESC LIMIT 200');
        $this->assertSqlContainsInOneCall('contribution_id IN (8,9)');
    }
}
