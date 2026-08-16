<?php
/**
 * Unit tests for GuidesShortcode limit handling and query contract.
 *
 * Covers spec case UT-SHORT-001.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use Brain\Monkey\Functions;
use CommonGoals\Frontend\GuidesShortcode;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class GuidesShortcodeUnitTest extends UnitTestCase
{
    public function test_ut_short_001_limit_clamped_to_minimum_one(): void
    {
        // absint(0) = 0, so max(1, min(50, 0)) = 1.
        $this->wpdb->queue_get_results([]);
        $shortcode = new GuidesShortcode();

        $shortcode->render_shortcode(['limit' => 0]);

        $this->assertLimitClause(1);
    }

    public function test_ut_short_001_negative_limit_becomes_positive_via_absint(): void
    {
        // absint(-5) = 5, documenting the current non-negative-then-clamp behavior.
        $this->wpdb->queue_get_results([]);
        $shortcode = new GuidesShortcode();

        $shortcode->render_shortcode(['limit' => -5]);

        $this->assertLimitClause(5);
    }

    public function test_ut_short_001_limit_clamped_to_maximum_fifty(): void
    {
        $this->wpdb->queue_get_results([]);
        $shortcode = new GuidesShortcode();

        $shortcode->render_shortcode(['limit' => 999]);

        $this->assertLimitClause(50);
    }

    public function test_ut_short_001_default_limit_is_twenty(): void
    {
        $this->wpdb->queue_get_results([]);
        $shortcode = new GuidesShortcode();

        $shortcode->render_shortcode([]);

        $this->assertLimitClause(20);
    }

    public function test_ut_short_001_query_filters_only_published_ordered_by_updated_desc(): void
    {
        $this->wpdb->queue_get_results([]);
        $shortcode = new GuidesShortcode();

        $shortcode->render_shortcode(['limit' => 20]);

        $this->assertSqlContainsInOneCall(['cg_guides', 'published', 'ORDER BY', 'updated_at', 'DESC']);
    }

    public function test_ut_short_001_enqueues_board_style(): void
    {
        $enqueued = [];
        Functions\when('wp_enqueue_style')->alias(static function ($handle) use (&$enqueued) {
            $enqueued[] = $handle;
        });
        $this->wpdb->queue_get_results([]);
        $shortcode = new GuidesShortcode();

        $shortcode->render_shortcode([]);

        $this->assertContains('common-goals-board', $enqueued);
    }

    private function assertLimitClause(int $expected): void
    {
        $matched = false;
        foreach ($this->wpdb->sql_strings() as $sql) {
            if (preg_match('/LIMIT\s+' . $expected . '\b/', $sql)) {
                $matched = true;
            }
        }
        $this->assertTrue($matched, "Expected LIMIT {$expected} clause not found");
    }
}
