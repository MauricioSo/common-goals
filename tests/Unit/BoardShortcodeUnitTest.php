<?php
/**
 * Unit tests for BoardShortcode registration, assets and empty-state render.
 *
 * Covers spec case UT-BOARD-001. Handler behavior (create/edit/delete) belongs
 * to the admin/public handler suite (spec 06).
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use Brain\Monkey\Functions;
use CommonGoals\Frontend\BoardShortcode;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class BoardShortcodeUnitTest extends UnitTestCase
{
    public function test_ut_board_001_register_hooks_registers_shortcode_seven_actions_and_assets(): void
    {
        $shortcodes = [];
        Functions\when('add_shortcode')->alias(static function ($tag, $cb) use (&$shortcodes) {
            $shortcodes[] = $tag;
        });

        $board = new BoardShortcode();
        $board->register_hooks();

        $this->assertContains('common_goals_board', $shortcodes);
        foreach ([
            'admin_post_common_goals_create_contribution',
            'admin_post_nopriv_common_goals_create_contribution',
            'admin_post_common_goals_create_response',
            'admin_post_nopriv_common_goals_create_response',
            'admin_post_common_goals_edit_contribution',
            'admin_post_common_goals_delete_contribution',
        ] as $hook) {
            $this->assertNotFalse(has_action($hook), "Missing hook: {$hook}");
        }
        $this->assertNotFalse(has_action('wp_enqueue_scripts'));
    }

    public function test_ut_board_001_register_assets_registers_style_and_script_with_version(): void
    {
        $styles = [];
        $scripts = [];
        Functions\when('wp_register_style')->alias(static function ($handle, $src, $deps, $ver) use (&$styles) {
            $styles[$handle] = ['src' => $src, 'ver' => $ver];
        });
        Functions\when('wp_register_script')->alias(static function ($handle, $src, $deps, $ver, $footer) use (&$scripts) {
            $scripts[$handle] = ['src' => $src, 'ver' => $ver, 'footer' => $footer];
        });

        $board = new BoardShortcode();
        $board->register_assets();

        $this->assertSame(COMMON_GOALS_VERSION, $styles['common-goals-board']['ver']);
        $this->assertSame(COMMON_GOALS_VERSION, $scripts['common-goals-board']['ver']);
        $this->assertStringContainsString('board.css', $styles['common-goals-board']['src']);
        $this->assertStringContainsString('board.js', $scripts['common-goals-board']['src']);
        $this->assertTrue($scripts['common-goals-board']['footer']);
    }

    public function test_ut_board_001_render_returns_empty_state_when_no_active_goal(): void
    {
        $this->wpdb->queue_get_row(null);
        $board = new BoardShortcode();

        $output = $board->render_shortcode([]);

        $this->assertStringContainsString('common-goals-board--empty', $output);
    }
}
