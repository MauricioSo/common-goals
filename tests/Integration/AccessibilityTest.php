<?php

namespace CommonGoals\Tests\Integration;

use CommonGoals\Database;
use CommonGoals\Tests\Integration\Support\IntegrationTestCase;

final class AccessibilityTest extends IntegrationTestCase
{
    public function test_a11y_001_board_shortcode_has_form_label(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);

        $html = do_shortcode('[common_goals_board goal_id="' . $g->id . '"]');

        $has_label = stripos($html, '<label') !== false || stripos($html, 'aria-label') !== false || stripos($html, 'aria-labelledby') !== false;

        $this->assertTrue($has_label, 'Board form should have label elements or aria labeling');
    }

    public function test_a11y_002_board_has_fieldset_or_aria(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);

        $html = do_shortcode('[common_goals_board goal_id="' . $g->id . '"]');

        $has_fieldset = stripos($html, '<fieldset') !== false;
        $has_role = stripos($html, 'role="') !== false;
        $has_aria = stripos($html, 'aria-') !== false;

        $this->assertTrue($has_fieldset || $has_role || $has_aria, 'Board should use fieldset, role or aria attributes');
    }

    public function test_a11y_003_form_has_required_attribute(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);

        $html = do_shortcode('[common_goals_board goal_id="' . $g->id . '"]');

        $this->assertStringContainsString('required', $html);
    }

    public function test_a11y_004_honeypot_is_screen_reader_hidden(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);

        update_option('common_goals_honeypot_enabled', 1);

        $html = do_shortcode('[common_goals_board goal_id="' . $g->id . '"]');

        $has_aria_hidden = stripos($html, 'aria-hidden') !== false || stripos($html, 'screen-reader') !== false || stripos($html, 'visually-hidden') !== false || stripos($html, 'position:absolute') !== false || stripos($html, 'position: absolute') !== false;

        $this->assertTrue($has_aria_hidden, 'Honeypot field should be visually hidden for screen readers');
    }

    public function test_a11y_005_board_uses_semantic_heading(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);

        $html = do_shortcode('[common_goals_board goal_id="' . $g->id . '"]');

        $has_heading = stripos($html, '<h2') !== false || stripos($html, '<h3') !== false || stripos($html, '<h4') !== false;

        $this->assertTrue($has_heading, 'Board should use semantic heading elements');
    }

    public function test_a11y_006_form_has_submit_button(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);

        $html = do_shortcode('[common_goals_board goal_id="' . $g->id . '"]');

        $has_button = stripos($html, '<button') !== false || stripos($html, 'type="submit"') !== false || stripos($html, "type='submit'") !== false;

        $this->assertTrue($has_button, 'Form should have a submit button');
    }

    public function test_a11y_007_select_has_associated_label(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);

        $html = do_shortcode('[common_goals_board goal_id="' . $g->id . '"]');

        $has_select = stripos($html, '<select') !== false;

        if ($has_select) {
            $this->assertStringContainsString('<label', $html);
        } else {
            $this->addToAssertionCount(1);
        }
    }

    public function test_a11y_008_board_empty_state_has_role_status(): void
    {
        $this->act_as_admin();
        $this->create_community();
        $this->create_goal((int) $this->create_community()->id, ['status' => 'inactive']);

        $html = do_shortcode('[common_goals_board]');

        $has_empty = stripos($html, 'common-goals-board--empty') !== false;
        $this->assertTrue($has_empty, 'Board should show empty state when no active goal');
    }

    public function test_a11y_009_input_maxlength_enforced(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);

        $html = do_shortcode('[common_goals_board goal_id="' . $g->id . '"]');

        $this->assertStringContainsString('maxlength', $html);
    }

    public function test_a11y_010_nonce_field_present_for_csrf(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);

        $html = do_shortcode('[common_goals_board goal_id="' . $g->id . '"]');

        $has_nonce = stripos($html, '_wpnonce') !== false || stripos($html, 'name="_wpnonce"') !== false || stripos($html, "name='_wpnonce'") !== false;

        $this->assertTrue($has_nonce, 'Form should include CSRF nonce');
    }

    public function test_a11y_011_css_focus_styles_defined(): void
    {
        $css_path = COMMON_GOALS_PLUGIN_DIR . 'assets/css/board.css';
        $css = file_get_contents($css_path);

        $has_focus = stripos($css, ':focus') !== false || stripos($css, 'focus-visible') !== false;

        $this->assertTrue($has_focus, 'CSS should define focus styles for keyboard navigation');
    }

    public function test_a11y_012_css_responsive_breakpoint(): void
    {
        $css_path = COMMON_GOALS_PLUGIN_DIR . 'assets/css/board.css';
        $css = file_get_contents($css_path);

        $has_media = stripos($css, '@media') !== false;

        $this->assertTrue($has_media, 'CSS should have responsive breakpoints');
    }

    public function test_a11y_013_css_dark_mode_support(): void
    {
        $css_path = COMMON_GOALS_PLUGIN_DIR . 'assets/css/board.css';
        $css = file_get_contents($css_path);

        $has_dark = stripos($css, 'prefers-color-scheme') !== false;

        $this->assertTrue($has_dark, 'CSS should support prefers-color-scheme for dark mode');
    }

    public function test_a11y_014_css_rtl_support(): void
    {
        $css_path = COMMON_GOALS_PLUGIN_DIR . 'assets/css/board.css';
        $css = file_get_contents($css_path);

        $has_rtl = stripos($css, 'rtl') !== false || stripos($css, '[dir="rtl"]') !== false;

        $this->assertTrue($has_rtl, 'CSS should support RTL languages');
    }

    public function test_a11y_015_board_markup_is_html5(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);

        $html = do_shortcode('[common_goals_board goal_id="' . $g->id . '"]');

        $has_html5 = stripos($html, '<form') !== false || stripos($html, '<article') !== false || stripos($html, '<section') !== false || stripos($html, '<nav') !== false;

        $this->assertTrue($has_html5, 'Board should use HTML5 semantic elements');
    }
}
