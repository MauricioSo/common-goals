<?php
/**
 * Admin template for editing Common Goals living guides.
 *
 * Expected variables:
 *
 * @var array $allowed_statuses Valid guide statuses.
 * @var array $guides           Recent guides.
 * @var array $communities      Communities available for filtering.
 * @var int   $selected_community Selected community ID.
 *
 * @package CommonGoals
 */

if (! defined('ABSPATH')) {
    exit;
}

$status_labels = [
    'draft'     => __('Draft', 'common-goals'),
    'review'    => __('In Review', 'common-goals'),
    'published' => __('Published', 'common-goals'),
    'hidden'    => __('Hidden', 'common-goals'),
];
?>

<div class="wrap">
    <h1><?php echo esc_html__('Common Goals Guides', 'common-goals'); ?></h1>

    <?php
    $notice_code = isset($_GET['common_goals_notice']) ? sanitize_key(wp_unslash($_GET['common_goals_notice'])) : '';
    $error_codes = ['invalid_guide', 'db_error'];
    if ($notice_code !== '') :
        $is_error = in_array($notice_code, $error_codes, true);
    ?>
        <div class="notice <?php echo $is_error ? 'notice-error' : 'notice-success'; ?> is-dismissible">
            <p><?php echo esc_html($is_error ? __('An error occurred. Please try again.', 'common-goals') : __('Action completed successfully.', 'common-goals')); ?></p>
        </div>
    <?php endif; ?>

    <form method="get" style="margin: 18px 0;">
        <input type="hidden" name="page" value="common-goals-guides" />
        <label for="community_id">
            <strong><?php echo esc_html__('Community', 'common-goals'); ?></strong><br />
            <select id="community_id" name="community_id">
                <option value="0"><?php echo esc_html__('All communities', 'common-goals'); ?></option>
                <?php foreach (($communities ?? []) as $community) : ?>
                    <option value="<?php echo esc_attr((string) $community->id); ?>" <?php selected($selected_community ?? 0, (int) $community->id); ?>><?php echo esc_html($community->name); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php submit_button(__('Filter', 'common-goals'), 'secondary', 'submit', false); ?>
    </form>

    <?php if (! empty($guides)) : ?>
        <?php foreach ($guides as $guide) : ?>
            <div class="card" style="max-width: 980px; margin-top: 18px; padding: 18px;">
                <h2><?php echo esc_html($guide->title); ?></h2>
                <p>
                    <strong><?php echo esc_html__('Slug:', 'common-goals'); ?></strong>
                    <code><?php echo esc_html($guide->slug); ?></code>
                    <strong><?php echo esc_html__('Status:', 'common-goals'); ?></strong>
                    <?php echo esc_html($guide->status); ?>
                    <strong><?php echo esc_html__('Community:', 'common-goals'); ?></strong>
                    <?php echo esc_html($guide->community_name ?: __('Unknown community', 'common-goals')); ?>
                </p>

                <details>
                    <summary><?php echo esc_html__('Edit guide', 'common-goals'); ?></summary>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 14px;">
                        <input type="hidden" name="action" value="common_goals_update_guide" />
                        <input type="hidden" name="guide_id" value="<?php echo esc_attr((string) $guide->id); ?>" />
                        <?php wp_nonce_field('common_goals_update_guide'); ?>

                        <p>
                            <label>
                                <strong><?php echo esc_html__('Title', 'common-goals'); ?></strong><br />
                                <input class="large-text" type="text" name="guide_title" value="<?php echo esc_attr($guide->title); ?>" required />
                            </label>
                        </p>

                        <p>
                            <label>
                                <strong><?php echo esc_html__('Slug', 'common-goals'); ?></strong><br />
                                <input class="large-text" type="text" name="guide_slug" value="<?php echo esc_attr($guide->slug); ?>" />
                            </label>
                        </p>

                        <p>
                            <label>
                                <strong><?php echo esc_html__('Content', 'common-goals'); ?></strong><br />
                                <textarea class="large-text" name="guide_content" rows="10" required><?php echo esc_textarea($guide->content); ?></textarea>
                            </label>
                        </p>

                        <p>
                            <label>
                                <strong><?php echo esc_html__('Status', 'common-goals'); ?></strong><br />
                                <select name="guide_status">
                                    <?php foreach ($allowed_statuses as $allowed_status) : ?>
                                        <option value="<?php echo esc_attr($allowed_status); ?>" <?php selected($guide->status, $allowed_status); ?>>
                                            <?php echo esc_html($status_labels[$allowed_status] ?? ucfirst($allowed_status)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </p>

                        <?php submit_button(__('Save guide', 'common-goals'), 'primary', 'submit', false); ?>
                    </form>
                </details>
            </div>
        <?php endforeach; ?>
    <?php else : ?>
        <p><?php echo esc_html__('No guides have been created yet.', 'common-goals'); ?></p>
    <?php endif; ?>
</div>
