<?php
/**
 * Unit tests for Privacy exporter, eraser and anonymization.
 *
 * Covers spec cases UT-PRIV-001 and UT-PRIV-002.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use Brain\Monkey\Functions;
use CommonGoals\Privacy;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class PrivacyUnitTest extends UnitTestCase
{
    public function test_ut_priv_001_register_hooks_registers_exporter_eraser_policy_and_delete(): void
    {
        Privacy::register_hooks();

        $this->assertNotFalse(has_filter('wp_privacy_personal_data_exporters'));
        $this->assertNotFalse(has_filter('wp_privacy_personal_data_erasers'));
        $this->assertNotFalse(has_action('admin_init'));
        $this->assertNotFalse(has_action('delete_user'));
    }

    public function test_ut_priv_001_register_exporter_adds_common_goals_key(): void
    {
        $result = Privacy::register_exporter(['existing' => []]);

        $this->assertArrayHasKey('existing', $result);
        $this->assertArrayHasKey('common-goals', $result);
        $this->assertSame('Common Goals Community Data', $result['common-goals']['exporter_friendly_name']);
    }

    public function test_ut_priv_001_export_returns_empty_for_unknown_email(): void
    {
        Functions\when('get_user_by')->justReturn(false);

        $result = Privacy::export_user_data('nobody@example.test');

        $this->assertSame([], $result['data']);
        $this->assertTrue($result['done']);
    }

    public function test_ut_priv_001_export_includes_memberships_contributions_and_responses(): void
    {
        Functions\when('get_user_by')->justReturn((object) ['ID' => 7]);
        $this->wpdb->queue_get_results([
            (object) ['community_id' => 1, 'role' => 'admin', 'created_at' => '2026-01-01', 'community_name' => 'Alpha'],
        ]);
        $this->wpdb->queue_get_results([
            (object) ['id' => 50, 'goal_id' => 1, 'type' => 'question', 'status' => 'open', 'topic' => '', 'title' => 'T', 'body' => 'B', 'created_at' => '2026-01-02'],
        ]);
        $this->wpdb->queue_get_results([
            (object) ['id' => 90, 'contribution_id' => 50, 'body' => 'R', 'created_at' => '2026-01-03'],
        ]);

        $result = Privacy::export_user_data('user@example.test');

        $this->assertCount(3, $result['data']);
        $this->assertSame('cg-membership-1', $result['data'][0]['item_id']);
        $this->assertSame('cg-contribution-50', $result['data'][1]['item_id']);
        $this->assertSame('cg-response-90', $result['data'][2]['item_id']);
        $this->assertTrue($result['done']);
    }

    public function test_ut_priv_002_eraser_anonymizes_user_id_and_created_by(): void
    {
        Functions\when('get_user_by')->justReturn((object) ['ID' => 7]);

        $result = Privacy::erase_user_data('user@example.test');

        $this->assertTrue($result['done']);
        // Four operations: delete members, update contributions, update responses, update events.
        $this->assertSame(1, $this->wpdb->count_method('delete'));
        $this->assertSame(3, $this->wpdb->count_method('update'));
        // Verify each update sets the identity column to 0.
        $updates = array_filter($this->wpdb->calls, static fn($c) => $c['method'] === 'update');
        $tables_updated = array_map(static fn($c) => $c['sql'], $updates);
        $this->assertContains('wp_cg_contributions', $tables_updated);
        $this->assertContains('wp_cg_responses', $tables_updated);
        $this->assertContains('wp_cg_events', $tables_updated);
        foreach ($updates as $update) {
            $this->assertContains(0, $update['extra']['data']);// identity set to 0
        }
    }

    public function test_ut_priv_002_eraser_returns_zero_removed_for_unknown_email(): void
    {
        Functions\when('get_user_by')->justReturn(false);

        $result = Privacy::erase_user_data('nobody@example.test');

        $this->assertSame(0, $result['items_removed']);
        $this->assertFalse($result['items_retained']);
        $this->assertSame(0, $this->wpdb->count_method('delete'));
    }

    public function test_ut_priv_002_anonymize_user_data_runs_four_operations(): void
    {
        Privacy::anonymize_user_data(7, null);

        $this->assertSame(3, $this->wpdb->count_method('update'));
        $this->assertSame(1, $this->wpdb->count_method('delete'));
    }

    public function test_ut_priv_002_anonymize_user_data_ignores_reassign_argument(): void
    {
        Privacy::anonymize_user_data(7, 99);

        // Reassign must not redirect content to user 99; updates still set 0.
        $updates = array_filter($this->wpdb->calls, static fn($c) => $c['method'] === 'update');
        foreach ($updates as $update) {
            $this->assertSame(0, $update['extra']['data'][array_key_first($update['extra']['data'])]);
        }
    }
}
