<?php
/**
 * Sitemap provider for published living guides.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers a custom sitemap provider so that guide pages appear in
 * wp-sitemap.xml alongside posts, taxonomies and users.
 */
final class GuideSitemap
{
    /**
     * Registers WordPress hooks.
     */
    public static function register_hooks(): void
    {
        add_filter('wp_sitemaps_register_providers', [self::class, 'register_provider']);
    }

    /**
     * Adds the guide sitemap provider to the registry.
     *
     * @param array<string, \WP_Sitemaps_Provider> $providers Existing providers.
     * @return array<string, \WP_Sitemaps_Provider>
     */
    public static function register_provider(array $providers): array
    {
        $providers['common_goals_guides'] = new GuideSitemapProvider();

        return $providers;
    }
}
