<?php
/**
 * Frontend shortcode for published living guides.
 *
 * @package CommonGoals
 */

namespace CommonGoals\Frontend;

use CommonGoals\Database;
use CommonGoals\TemplateLoader;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Displays public living guides created from community contributions.
 */
final class GuidesShortcode
{
    /**
     * Registers shortcode hooks.
     */
    public function register_hooks(): void
    {
        add_shortcode('common_goals_guides', [$this, 'render_shortcode']);
    }

    /**
     * Renders the public guides shortcode.
     *
     * @param array<string, mixed> $attributes Shortcode attributes.
     */
    public function render_shortcode(array $attributes): string
    {
        global $wpdb;

        wp_enqueue_style('common-goals-board');

        $attributes = shortcode_atts(
            [
                'limit' => 20,
            ],
            $attributes,
            'common_goals_guides'
        );

        $limit        = max(1, min(50, absint($attributes['limit'])));
        $guides_table = Database::guides_table();
        $guides       = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$guides_table} WHERE status = 'published' ORDER BY updated_at DESC LIMIT %d",
                $limit
            )
        );

        ob_start();
        include TemplateLoader::locate('guides.php');

        return (string) ob_get_clean();
    }
}
