<?php
/**
 * Property-based tests for pagination contracts (REST contributions, guides
 * shortcode/REST and sitemap provider).
 *
 * Covers spec cases PB-PAGE-002 and PB-PAGE-003.
 *
 * @package CommonGoals\Tests\Property
 */

namespace CommonGoals\Tests\Property;

use Brain\Monkey\Functions;
use CommonGoals\GuideSitemapProvider;
use CommonGoals\RestApi;
use CommonGoals\Tests\Property\Support\PropertyRng;
use CommonGoals\Tests\Property\Support\PropertyTestCase;
use CommonGoals\Tests\Unit\Support\RequestStub;

final class PaginationPropertyTest extends PropertyTestCase
{
    public function test_pb_page_002_rest_contributions_clamps_per_page_and_offsets(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            $page = $rng->between(-5, 50);
            $per_page = $rng->between(-5, 200);

            $this->wpdb->queue_get_results([]);
            $this->wpdb->queue_get_var('0');

            RestApi::get_contributions(new RequestStub(['page' => $page, 'per_page' => $per_page]));

            $expected_per_page = max(1, min(50, abs($per_page)));
            $expected_page = max(1, abs($page));
            $expected_offset = ($expected_page - 1) * $expected_per_page;

            $sqls = $this->wpdb->sql_strings();
            $found = false;
            foreach ($sqls as $sql) {
                if (preg_match('/LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/', $sql, $m)) {
                    $this->assertSame($expected_per_page, (int) $m[1], 'per_page must clamp to [0,50]');
                    $this->assertSame($expected_offset, (int) $m[2], 'offset must be (page-1)*per_page');
                    $found = true;
                }
            }
            $this->assertTrue($found, 'A LIMIT/OFFSET clause must always be present');
        }, 300, 'PB-PAGE-002');
    }

    public function test_pb_page_003_sitemap_offset_is_page_minus_one_times_2000(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            $page = $rng->between(1, 50);
            $this->wpdb->queue_get_results([]);

            $provider = new GuideSitemapProvider();
            $provider->get_url_list($page);

            $expected_offset = ($page - 1) * 2000;
            $this->assertSqlContainsOffset($expected_offset);
        }, 100, 'PB-PAGE-003');
    }

    public function test_pb_page_003_max_pages_is_ceil_of_total_divided_by_2000(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            $total = $rng->between(0, 100000);
            $this->wpdb->queue_get_var((string) $total);

            $provider = new GuideSitemapProvider();
            $pages = $provider->get_max_num_pages();

            $expected = $total > 0 ? (int) ceil($total / 2000) : 0;
            $this->assertSame($expected, $pages);
        }, 200, 'PB-PAGE-003-max');
    }

    private function assertSqlContainsOffset(int $expected): void
    {
        $found = false;
        foreach ($this->wpdb->sql_strings() as $sql) {
            if (preg_match('/OFFSET\s+' . $expected . '\b/', $sql)) {
                $found = true;
            }
        }
        $this->assertTrue($found, "Expected OFFSET {$expected} not found");
    }
}
