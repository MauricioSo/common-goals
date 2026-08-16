<?php
/**
 * Admin settings page for the AI assistant.
 *
 * @package CommonGoals\Admin
 */

namespace CommonGoals\Admin;

use CommonGoals\AI\BudgetGuard;
use CommonGoals\AI\Settings;
use CommonGoals\Capabilities;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers and renders the AI assistant configuration screen.
 *
 * Settings are stored in a single option ({@see Settings::OPTION_NAME}) and
 * are sanitized through {@see self::sanitize()} so the API key and flags can
 * never be persisted in an invalid shape.
 */
final class AiSettingsPage
{
    public const PAGE_SLUG = 'common-goals-ai-settings';

    /**
     * Registers admin hooks.
     */
    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_common_goals_ai_test', [$this, 'handle_test']);
    }

    /**
     * Adds the settings submenu.
     */
    public function register_admin_menu(): void
    {
        if (! current_user_can(Capabilities::MANAGE)) {
            return;
        }

        add_submenu_page(
            'common-goals',
            __('AI Assistant', 'common-goals'),
            __('AI Assistant', 'common-goals'),
            Capabilities::MANAGE,
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    /**
     * Registers the settings option with a sanitizer.
     */
    public function register_settings(): void
    {
        register_setting(
            'common_goals_ai_settings_group',
            Settings::OPTION_NAME,
            ['sanitize_callback' => [$this, 'sanitize'], 'default' => []]
        );
    }

    /**
     * Sanitizes the posted settings array.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function sanitize(array $input): array
    {
        $defaults = Settings::defaults();
        $input    = is_array($input) ? $input : [];

        $clean                  = $defaults;
        $clean['api_key']       = trim(sanitize_text_field((string) ($input['api_key'] ?? '')));
        $clean['base_url']      = esc_url_raw(trim((string) ($input['base_url'] ?? $defaults['base_url']))) ?: $defaults['base_url'];
        $clean['model']         = sanitize_text_field((string) ($input['model'] ?? $defaults['model']));
        $clean['temperature']   = max(0.0, min(2.0, (float) ($input['temperature'] ?? $defaults['temperature'])));
        $clean['max_tokens']    = max(64, min(8000, (int) ($input['max_tokens'] ?? $defaults['max_tokens'])));
        $clean['timeout']       = max(5, min(120, (int) ($input['timeout'] ?? $defaults['timeout'])));
        $clean['monthly_budget_usd'] = max(0.0, min(1000.0, (float) ($input['monthly_budget_usd'] ?? $defaults['monthly_budget_usd'])));
        $clean['consent_notice']= sanitize_textarea_field((string) ($input['consent_notice'] ?? $defaults['consent_notice']));
        $clean['share_content'] = (bool) ($input['share_content'] ?? false);

        $enabled = [];
        foreach (Settings::flow_ids() as $id) {
            $enabled[$id] = (bool) ($input['enabled_flows'][$id] ?? false);
        }
        $clean['enabled_flows'] = $enabled;

        // Preserve the existing API key when the field was left masked/empty on save.
        if ($clean['api_key'] === '' || str_contains($clean['api_key'], '*')) {
            $clean['api_key'] = Settings::api_key();
        }

        return $clean;
    }

    /**
     * Renders the settings page.
     */
    public function render_page(): void
    {
        if (! current_user_can(Capabilities::MANAGE)) {
            wp_die(esc_html__('You do not have permission to manage these settings.', 'common-goals'));
        }

        $settings = Settings::all();
        $spent    = BudgetGuard::monthly_spend();
        include COMMON_GOALS_PLUGIN_DIR . 'templates/admin-ai-settings-page.php';
    }

    /**
     * Handles the "send test request" admin-post action.
     */
    public function handle_test(): void
    {
        if (! current_user_can(Capabilities::MANAGE)) {
            wp_die(esc_html__('Forbidden.', 'common-goals'));
        }

        check_admin_referer('common_goals_ai_test');

        $client  = new \CommonGoals\AI\Client();
        $result  = $client->complete(
            \CommonGoals\AI\Prompts::system(),
            [['role' => 'user', 'content' => 'Reply with the single word: ok']],
            ['temperature' => 0.0, 'max_tokens' => 16]
        );

        $redirect = add_query_arg(
            ['page' => self::PAGE_SLUG, 'ai_test' => $result->ok ? '1' : '0'],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }
}
