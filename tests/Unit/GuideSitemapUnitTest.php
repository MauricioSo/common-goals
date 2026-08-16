<?php
/**
 * Unit tests for GuideSitemap provider registration and pagination.
 *
 * Covers spec case UT-SMAP-001.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use Brain\Monkey\Filters;
use CommonGoals\GuideSitemap;
use CommonGoals\GuideSitemapProvider;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class GuideSitemapUnitTest extends UnitTestCase
{
    public function test_ut_smap_001_register_hooks_attaches_provider_filter(): void
    {
        GuideSitemap::register_hooks();

        $this->assertNotFalse(has_filter('wp_sitemaps_register_providers'));
    }

    public function test_ut_smap_001_register_provider_replaces_own_key_and_preserves_others(): void
    {
        $existing = ['posts' => new class {}, 'pages' => new class {}];

        $result = GuideSitemap::register_provider($existing);

        $this->assertArrayHasKey('posts', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertArrayHasKey('common_goals_guides', $result);
        $this->assertInstanceOf(GuideSitemapProvider::class, $result['common_goals_guides']);
    }

    public function test_ut_smap_001_register_provider_is_idempotent_for_own_key(): void
    {
        $result = GuideSitemap::register_provider(GuideSitemap::register_provider([]));

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('common_goals_guides', $result);
    }

    public function test_ut_smap_001_get_url_list_uses_2000_per_page_and_offset(): void
    {
        $this->wpdb->queue_get_results([]);
        $provider = new GuideSitemapProvider();

        $provider->get_url_list(3);

        $this->assertSqlContainsInOneCall(['cg_guides', 'published', 'LIMIT 2000', 'OFFSET 4000']);
    }

    public function test_ut_smap_001_get_url_list_only_returns_published_guides(): void
    {
        $this->wpdb->queue_get_results([
            (object) ['slug' => 'guide-a', 'updated_at' => '2026-01-01'],
        ]);
        $provider = new GuideSitemapProvider();

        $urls = $provider->get_url_list(1);

        $this->assertCount(1, $urls);
        $this->assertSame('https://example.test/guias/guide-a/', $urls[0]['loc']);
        $this->assertSqlContainsInOneCall(['published']);
    }

    public function test_ut_smap_001_max_num_pages_is_zero_when_no_guides(): void
    {
        $this->wpdb->queue_get_var('0');
        $provider = new GuideSitemapProvider();

        $this->assertSame(0, $provider->get_max_num_pages());
    }

    public function test_ut_smap_001_max_num_pages_ceil_divides_by_2000(): void
    {
        $this->wpdb->queue_get_var('4001');
        $provider = new GuideSitemapProvider();

        $this->assertSame(3, $provider->get_max_num_pages());
    }
}
