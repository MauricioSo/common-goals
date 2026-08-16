<?php
/**
 * Gutenberg block registration.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers the Common Goals Gutenberg blocks using block.json files
 * and server-side render callbacks.
 */
final class Blocks
{
    /**
     * Registers WordPress hooks.
     */
    public static function register_hooks(): void
    {
        add_action('init', [self::class, 'register_blocks']);
    }

    /**
     * Registers all blocks from their block.json files.
     */
    public static function register_blocks(): void
    {
        $blocks = [
            'board'   => COMMON_GOALS_PLUGIN_DIR . 'assets/js/blocks/board/block.json',
            'guides'  => COMMON_GOALS_PLUGIN_DIR . 'assets/js/blocks/guides/block.json',
        ];

        foreach ($blocks as $name => $path) {
            if (! file_exists($path)) {
                continue;
            }

            register_block_type($path);
        }
    }
}
