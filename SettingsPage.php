<?php
/**
 * Admin settings page using the WordPress Settings API.
 *
 * @package CommonGoals
 */

namespace CommonGoals\Admin;

use CommonGoals\Capabilities;
use CommonGoals\Domain;
use CommonGoals\SiteHealth;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers and renders the plugin settings screen so that site admins
 * can configure Common Goals without touching WP-CLI or custom code.
 */
final class SettingsPage
{
    public const OPTION_GROUP  = 'common_goals_settings';
    public const PAGE_SLUG      = 'common-goals-settings';

    /**
     * Registers admin hooks.
     */
    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Adds the settings submenu under Common Goals.
     */
    public function register_admin_menu(): void
    {
        add_submenu_page(
            'common-goals',
            __('Settings', 'common-goals'),
            __('Settings', 'common-goals'),
            Capabilities::MANAGE,
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    /**
     * Registers all settings, sections and fields.
     */
    public function register_settings(): void
    {
        register_setting(self::OPTION_GROUP, 'common_goals_allow_guest_posting', ['default' => 1]);
        register_setting(self::OPTION_GROUP, SiteHealth::RETENTION_OPTION, ['default' => SiteHealth::DEFAULT_RETENTION, 'sanitize_callback' => 'absint']);
        register_setting(self::OPTION_GROUP, 'common_goals_cleanup_on_uninstall', ['default' => 0]);
        register_setting(self::OPTION_GROUP, 'common_goals_rate_limit_max', ['default' => Domain::RATE_LIMIT_MAX, 'sanitize_callback' => 'absint']);
        register_setting(self::OPTION_GROUP, 'common_goals_honeypot_enabled', ['default' => 1]);

        add_settings_section('cg_general', __('General', 'common-goals'), '__return_empty_string', self::PAGE_SLUG);
        add_settings_section('cg_moderation', __('Moderation', 'common-goals'), '__return_empty_string', self::PAGE_SLUG);
        add_settings_section('cg_privacy', __('Privacy and data', 'common-goals'), '__return_empty_string', self::PAGE_SLUG);

        add_settings_field('cg_allow_guest_posting', __('Allow guest posting', 'common-goals'), [$this, 'render_checkbox'], self::PAGE_SLUG, 'cg_general', ['option' => 'common_goals_allow_guest_posting', 'description' => __('When enabled, unauthenticated visitors can submit contributions and responses. All guest submissions enter the pending queue.', 'common-goals')]);
        add_settings_field('cg_cleanup', __('Delete all data on uninstall', 'common-goals'), [$this, 'render_checkbox'], self::PAGE_SLUG, 'cg_privacy', ['option' => 'common_goals_cleanup_on_uninstall', 'description' => __('Removes all tables, options and capabilities when the plugin is uninstalled. Off by default to prevent data loss.', 'common-goals')]);

        add_settings_field('cg_rate_limit', __('Rate limit (submissions per window)', 'common-goals'), [$this, 'render_number'], self::PAGE_SLUG, 'cg_moderation', ['option' => 'common_goals_rate_limit_max', 'min' => 1, 'max' => 50, 'description' => __('Maximum submissions per user within the rate limit window.', 'common-goals')]);
        add_settings_field('cg_honeypot', __('Enable honeypot', 'common-goals'), [$this, 'render_checkbox'], self::PAGE_SLUG, 'cg_moderation', ['option' => 'common_goals_honeypot_enabled', 'description' => __('Hidden field that catches basic bots without affecting human users.', 'common-goals')]);

        add_settings_field('cg_retention', __('Event log retention (days)', 'common-goals'), [$this, 'render_number'], self::PAGE_SLUG, 'cg_privacy', ['option' => SiteHealth::RETENTION_OPTION, 'min' => 1, 'max' => 3650, 'description' => __('Events older than this are automatically deleted by a daily cron job.', 'common-goals')]);
    }

    /**
     * Renders a checkbox field.
     *
     * @param array<string, mixed> $args Field arguments.
     */
    public function render_checkbox(array $args): void
    {
        $option = $args['option'];
        $value  = (bool) get_option($option, 0);

        echo '<label><input type="checkbox" name="' . esc_attr($option) . '" value="1" ' . checked($value, true, false) . ' /> ' . esc_html__('Enabled', 'common-goals') . '</label>';
        echo '<p class="description">' . esc_html($args['description'] ?? '') . '</p>';
    }

    /**
     * Renders a number input field.
     *
     * @param array<string, mixed> $args Field arguments.
     */
    public function render_number(array $args): void
    {
        $option = $args['option'];
        $value  = get_option($option, 0);
        $min    = $args['min'] ?? 0;
        $max    = $args['max'] ?? 9999;

        echo '<input type="number" name="' . esc_attr($option) . '" value="' . esc_attr((string) $value) . '" min="' . esc_attr((string) $min) . '" max="' . esc_attr((string) $max) . '" class="small-text" />';
        echo '<p class="description">' . esc_html($args['description'] ?? '') . '</p>';
    }

    /**
     * Renders the settings page.
     */
    public function render_page(): void
    {
        if (! current_user_can(Capabilities::MANAGE)) {
            wp_die(esc_html__('You do not have permission to manage Common Goals settings.', 'common-goals'));
        }

        include COMMON_GOALS_PLUGIN_DIR . 'templates/admin-settings-page.php';
    }
}
