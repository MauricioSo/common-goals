<?php
/**
 * Server-side render callback for the Common Goals Board block.
 *
 * @package CommonGoals
 */

namespace CommonGoals\Blocks;

use CommonGoals\Frontend\BoardShortcode;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renders the board block by delegating to the BoardShortcode.
 *
 * @param array<string, mixed> $attributes Block attributes.
 * @return string Rendered HTML.
 */
function render_board_block(array $attributes): string
{
    $shortcode = new BoardShortcode();

    return $shortcode->render_shortcode([
        'goal_id'      => $attributes['goal_id'] ?? 0,
        'community_id' => $attributes['community_id'] ?? 0,
    ]);
}
