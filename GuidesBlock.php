<?php
/**
 * Server-side render callback for the Common Goals Guides block.
 *
 * @package CommonGoals
 */

namespace CommonGoals\Blocks;

use CommonGoals\Frontend\GuidesShortcode;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renders the guides block by delegating to the GuidesShortcode.
 *
 * @param array<string, mixed> $attributes Block attributes.
 * @return string Rendered HTML.
 */
function render_guides_block(array $attributes): string
{
    $shortcode = new GuidesShortcode();

    return $shortcode->render_shortcode([
        'limit' => $attributes['limit'] ?? 20,
    ]);
}
