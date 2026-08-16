<?php
/**
 * WordPress core sitemap provider for Common Goals guide pages.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Extends the core WP_Sitemaps_Provider to expose published guides
 * in the WordPress sitemap index.
 */
final class GuideSitemapProvider extends \WP_Sitemaps_Provider
{
    /**
     * Gets a URL list for a sitemap page.
     *
     * @param int    $page_num       Page number (1-based).
     * @param string $object_subtype Optional. Not used. Default empty.
     * @return array<int, array<string, string>> URL entries.
     */
    public function get_url_list($page_num, $object_subtype = '')
    {
        global $wpdb;

        $per_page    = 2000;
        $offset      = ($page_num - 1) * $per_page;
        $guides_table = Database::guides_table();

        $guides = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT slug, updated_at FROM {$guides_table} WHERE status = 'published' ORDER BY updated_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );

        $urls = [];

        foreach ($guides as $guide) {
            $urls[] = [
                'loc' => Frontend\GuideRouter::guide_url($guide->slug),
            ];
        }

        return $urls;
    }

    /**
     * Returns the number of pages in the sitemap.
     *
     * @param string $object_subtype Optional. Not used. Default empty.
     * @return int
     */
    public function get_max_num_pages($object_subtype = '')
    {
        global $wpdb;

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Database::guides_table() . " WHERE status = 'published'");

        return $total > 0 ? (int) ceil($total / 2000) : 0;
    }
}
