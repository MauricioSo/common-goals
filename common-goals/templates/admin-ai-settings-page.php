<?php
/**
 * Admin template for the AI assistant settings page.
 *
 * @var array<string, mixed> $settings Resolved settings array.
 * @var float                $spent    USD spent this month.
 *
 * @package CommonGoals
 */

if (! defined('ABSPATH')) {
    exit;
}

$opt   = Settings::OPTION_NAME;
$masked = Settings::masked_api_key();
$test   = isset($_GET['ai_test']) ? sanitize_key(wp_unslash($_GET['ai_test'])) : '';
?>
<div class="wrap">
    <h1><?php echo esc_html__('AI Assistant', 'common-goals'); ?></h1>

    <?php if ($test === '1') : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Test request succeeded. The assistant is reachable.', 'common-goals'); ?></p></div>
    <?php elseif ($test === '0') : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html__('Test request failed. Check the API key, base URL and that the provider is reachable.', 'common-goals'); ?></p></div>
    <?php endif; ?>

    <div class="notice notice-info">
        <p><strong><?php echo esc_html__('Human-in-the-loop', 'common-goals'); ?>:</strong> <?php echo esc_html__('The assistant only suggests. Publishing, moderation and guide creation always require a person to confirm.', 'common-goals'); ?></p>
    </div>

    <h2 class="title"><?php echo esc_html__('Budget this month', 'common-goals'); ?></h2>
    <p><?php echo esc_html(sprintf('Spent $%s of $%s budget.', number_format((float) $spent, 4), number_format((float) $settings['monthly_budget_usd'], 2))); ?></p>

    <form method="post" action="options.php">
        <?php settings_fields('common_goals_ai_settings_group'); ?>

        <h2 class="title"><?php echo esc_html__('Provider', 'common-goals'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cg-ai-key"><?php echo esc_html__('API key', 'common-goals'); ?></label></th>
                <td>
                    <input id="cg-ai-key" type="password" name="<?php echo esc_attr($opt); ?>[api_key]" value="<?php echo esc_attr($masked); ?>" class="regular-text" autocomplete="off" />
                    <p class="description"><?php echo esc_html(sprintf(__('Currently %s. Stored locally; never logged or sent to clients.', 'common-goals'), $masked === '' ? __('empty', 'common-goals') : $masked)); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cg-ai-base"><?php echo esc_html__('Base URL', 'common-goals'); ?></label></th>
                <td><input id="cg-ai-base" type="url" name="<?php echo esc_attr($opt); ?>[base_url]" value="<?php echo esc_attr((string) $settings['base_url']); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="cg-ai-model"><?php echo esc_html__('Model', 'common-goals'); ?></label></th>
                <td><input id="cg-ai-model" type="text" name="<?php echo esc_attr($opt); ?>[model]" value="<?php echo esc_attr((string) $settings['model']); ?>" class="regular-text" />
                    <p class="description"><?php echo esc_html__('Default: deepseek-v4-flash (OpenAI-compatible).', 'common-goals'); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Send test request', 'common-goals'); ?></th>
                <td>
                    <?php $test_url = wp_nonce_url(add_query_arg(['page' => self::PAGE_SLUG, 'action' => 'common_goals_ai_test'], admin_url('admin-post.php')), 'common_goals_ai_test'); ?>
                    <a class="button" href="<?php echo esc_url($test_url); ?>"><?php echo esc_html__('Test connection', 'common-goals'); ?></a>
                    <p class="description"><?php echo esc_html__('Saves are not required before testing.', 'common-goals'); ?></p>
                </td>
            </tr>
        </table>

        <h2 class="title"><?php echo esc_html__('Generation', 'common-goals'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cg-ai-temp"><?php echo esc_html__('Temperature', 'common-goals'); ?></label></th>
                <td><input id="cg-ai-temp" type="number" step="0.1" min="0" max="2" name="<?php echo esc_attr($opt); ?>[temperature]" value="<?php echo esc_attr((string) $settings['temperature']); ?>" class="small-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="cg-ai-tokens"><?php echo esc_html__('Max tokens', 'common-goals'); ?></label></th>
                <td><input id="cg-ai-tokens" type="number" min="64" max="8000" name="<?php echo esc_attr($opt); ?>[max_tokens]" value="<?php echo esc_attr((string) $settings['max_tokens']); ?>" class="small-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="cg-ai-timeout"><?php echo esc_html__('Timeout (seconds)', 'common-goals'); ?></label></th>
                <td><input id="cg-ai-timeout" type="number" min="5" max="120" name="<?php echo esc_attr($opt); ?>[timeout]" value="<?php echo esc_attr((string) $settings['timeout']); ?>" class="small-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="cg-ai-budget"><?php echo esc_html__('Monthly budget (USD)', 'common-goals'); ?></label></th>
                <td><input id="cg-ai-budget" type="number" step="0.5" min="0" max="1000" name="<?php echo esc_attr($opt); ?>[monthly_budget_usd]" value="<?php echo esc_attr((string) $settings['monthly_budget_usd']); ?>" class="small-text" /></td>
            </tr>
        </table>

        <h2 class="title"><?php echo esc_html__('Enabled flows', 'common-goals'); ?></h2>
        <table class="form-table" role="presentation">
            <?php foreach (Settings::flow_ids() as $id) : $meta = Settings::flow_meta($id); ?>
                <tr>
                    <th scope="row"><?php echo esc_html($meta['label']); ?></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[enabled_flows][<?php echo esc_attr($id); ?>]" value="1" <?php checked((bool) ($settings['enabled_flows'][$id] ?? false)); ?> /> <?php echo esc_html($id); ?></label>
                        <span class="description"><?php echo esc_html($meta['phase'] === 'mvp' ? __('MVP', 'common-goals') : __('Phase 2', 'common-goals')); ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <h2 class="title"><?php echo esc_html__('Privacy and consent', 'common-goals'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php echo esc_html__('Share community content', 'common-goals'); ?></th>
                <td><label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[share_content]" value="1" <?php checked((bool) $settings['share_content']); ?> /> <?php echo esc_html__('Allow sending public contributions to the model.', 'common-goals'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><label for="cg-ai-consent"><?php echo esc_html__('Consent notice', 'common-goals'); ?></label></th>
                <td><textarea id="cg-ai-consent" name="<?php echo esc_attr($opt); ?>[consent_notice]" rows="3" class="large-text"><?php echo esc_textarea((string) $settings['consent_notice']); ?></textarea></td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>
</div>
