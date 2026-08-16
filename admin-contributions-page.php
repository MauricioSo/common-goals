<?php
/**
 * Admin template for reviewing Common Goals contributions.
 *
 * Expected variables:
 *
 * @var array  $allowed_statuses   Valid contribution statuses.
 * @var array  $response_statuses  Valid response statuses.
 * @var array  $contributions      Recent contributions.
 * @var array  $responses_by_cid   Responses grouped by contribution ID.
 * @var array  $communities        Communities available for filtering.
 * @var int    $selected_community Selected community ID.
 *
 * @package CommonGoals
 */

if (! defined('ABSPATH')) {
    exit;
}

$status_labels = [
    'pending'     => __('Pending', 'common-goals'),
    'open'        => __('Open', 'common-goals'),
    'in_progress' => __('In Progress', 'common-goals'),
    'resolved'    => __('Resolved', 'common-goals'),
    'spam'        => __('Spam', 'common-goals'),
    'hidden'      => __('Hidden', 'common-goals'),
];

$response_status_labels = [
    'pending'   => __('Pending', 'common-goals'),
    'published' => __('Published', 'common-goals'),
    'spam'      => __('Spam', 'common-goals'),
    'hidden'    => __('Hidden', 'common-goals'),
];
?>

<div class="wrap">
    <h1><?php echo esc_html__('Common Goals Contributions', 'common-goals'); ?></h1>

    <?php
    $notice_code = isset($_GET['common_goals_notice']) ? sanitize_key(wp_unslash($_GET['common_goals_notice'])) : '';
    $error_codes = ['invalid_guide', 'missing_contribution', 'invalid_status', 'db_error', 'guide_already_exists', 'invalid_bulk', 'missing_response'];
    if ($notice_code !== '') :
        $is_error = in_array($notice_code, $error_codes, true);
    ?>
        <div class="notice <?php echo $is_error ? 'notice-error' : 'notice-success'; ?> is-dismissible">
            <p><?php echo esc_html($is_error ? __('An error occurred. Please try again.', 'common-goals') : __('Action completed successfully.', 'common-goals')); ?></p>
        </div>
    <?php endif; ?>

    <form method="get" style="margin: 18px 0;">
        <input type="hidden" name="page" value="common-goals-contributions" />
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

    <?php if (! empty($contributions)) : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 18px 0;">
            <input type="hidden" name="action" value="common_goals_bulk_moderate" />
            <?php wp_nonce_field('common_goals_bulk_moderate'); ?>

            <div style="align-items: end; display: flex; flex-wrap: wrap; gap: 10px;">
                <label for="bulk_status">
                    <strong><?php echo esc_html__('Bulk action', 'common-goals'); ?></strong><br />
                    <select id="bulk_status" name="bulk_status">
                        <?php foreach ($status_labels as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php submit_button(__('Apply to selected', 'common-goals'), 'secondary', 'submit', false); ?>
            </div>

        <?php foreach ($contributions as $contribution) : ?>
            <div class="card" style="max-width: 980px; margin-top: 18px; padding: 18px;">
                <p style="display: flex; align-items: center; gap: 10px;">
                    <input type="checkbox" name="contribution_ids[]" value="<?php echo esc_attr((string) $contribution->id); ?>" id="contribution_<?php echo esc_attr((string) $contribution->id); ?>" />
                    <label for="contribution_<?php echo esc_attr((string) $contribution->id); ?>">
                        <strong><?php echo esc_html($contribution->title); ?></strong>
                        <?php if (! empty($contribution->is_sticky)) : ?>
                            <span style="background:#c77f1c;color:#fff;border-radius:999px;font-size:11px;font-weight:700;padding:3px 9px;text-transform:uppercase;letter-spacing:.03em;"><?php echo esc_html__('Pinned', 'common-goals'); ?></span>
                        <?php endif; ?>
                    </label>
                </p>
                <p>
                    <strong><?php echo esc_html__('Goal:', 'common-goals'); ?></strong>
                    <?php echo esc_html($contribution->goal_title ?: __('Unknown goal', 'common-goals')); ?>
                    <strong><?php echo esc_html__('Community:', 'common-goals'); ?></strong>
                    <?php echo esc_html($contribution->community_name ?: __('Unknown community', 'common-goals')); ?>
                    <strong><?php echo esc_html__('Type:', 'common-goals'); ?></strong>
                    <?php echo esc_html($contribution->type); ?>
                    <strong><?php echo esc_html__('Status:', 'common-goals'); ?></strong>
                    <?php echo esc_html($status_labels[$contribution->status] ?? ucfirst($contribution->status)); ?>
                </p>
                <div><?php echo \CommonGoals\Markdown::render($contribution->body); ?></div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="align-items: end; display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px;">
                    <input type="hidden" name="action" value="common_goals_update_contribution_status" />
                    <input type="hidden" name="contribution_id" value="<?php echo esc_attr((string) $contribution->id); ?>" />
                    <?php wp_nonce_field('common_goals_update_contribution_status'); ?>

                    <label for="contribution_status_<?php echo esc_attr((string) $contribution->id); ?>">
                        <strong><?php echo esc_html__('Moderation status', 'common-goals'); ?></strong><br />
                        <select id="contribution_status_<?php echo esc_attr((string) $contribution->id); ?>" name="contribution_status">
                            <?php foreach ($allowed_statuses as $allowed_status) : ?>
                                <option value="<?php echo esc_attr($allowed_status); ?>" <?php selected($contribution->status, $allowed_status); ?>>
                                    <?php echo esc_html($status_labels[$allowed_status] ?? ucfirst($allowed_status)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <?php submit_button(__('Update status', 'common-goals'), 'secondary', 'submit', false); ?>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-top:14px;">
                    <input type="hidden" name="action" value="common_goals_toggle_sticky" />
                    <input type="hidden" name="contribution_id" value="<?php echo esc_attr((string) $contribution->id); ?>" />
                    <input type="hidden" name="is_sticky" value="<?php echo ! empty($contribution->is_sticky) ? 0 : 1; ?>" />
                    <?php wp_nonce_field('common_goals_toggle_sticky'); ?>
                    <?php submit_button(! empty($contribution->is_sticky) ? __('Unpin from top', 'common-goals') : __('Pin to top', 'common-goals'), 'secondary', 'submit', false); ?>
                </form>

                <?php
                $contribution_responses = $responses_by_cid[(int) $contribution->id] ?? [];
                if (! empty($contribution_responses)) :
                ?>
                    <div style="margin-top: 18px;">
                        <h3><?php echo esc_html__('Responses', 'common-goals'); ?></h3>
                        <?php foreach ($contribution_responses as $response) : ?>
                            <div style="background: #f8fafc; border: 1px solid #e4ebf4; border-radius: 10px; margin-bottom: 10px; padding: 12px;">
                                <div><?php echo \CommonGoals\Markdown::render($response->body); ?></div>
                                <p style="color: #506176; font-size: 13px;">
                                    <?php echo esc_html__('Status:', 'common-goals'); ?>
                                    <?php echo esc_html($response_status_labels[$response->status] ?? ucfirst($response->status)); ?>
                                    <?php echo esc_html('· User #' . $response->user_id); ?>
                                </p>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="align-items: end; display: flex; flex-wrap: wrap; gap: 10px; margin-top: 8px;">
                                    <input type="hidden" name="action" value="common_goals_update_response_status" />
                                    <input type="hidden" name="response_id" value="<?php echo esc_attr((string) $response->id); ?>" />
                                    <?php wp_nonce_field('common_goals_update_response_status'); ?>

                                    <label>
                                        <select name="response_status">
                                            <?php foreach ($response_statuses as $rs) : ?>
                                                <option value="<?php echo esc_attr($rs); ?>" <?php selected($response->status, $rs); ?>>
                                                    <?php echo esc_html($response_status_labels[$rs] ?? ucfirst($rs)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>

                                    <?php submit_button(__('Update', 'common-goals'), 'secondary', 'submit', false); ?>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <details style="margin-top: 14px;">
                    <summary><?php echo esc_html__('Create living guide from this contribution', 'common-goals'); ?></summary>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 14px;">
                        <input type="hidden" name="action" value="common_goals_create_guide" />
                        <input type="hidden" name="contribution_id" value="<?php echo esc_attr((string) $contribution->id); ?>" />
                        <?php wp_nonce_field('common_goals_create_guide'); ?>

                        <p>
                            <label for="guide_title_<?php echo esc_attr((string) $contribution->id); ?>">
                                <strong><?php echo esc_html__('Guide title', 'common-goals'); ?></strong>
                            </label>
                        </p>
                        <p>
                            <input id="guide_title_<?php echo esc_attr((string) $contribution->id); ?>" class="large-text" type="text" name="guide_title" value="<?php echo esc_attr($contribution->title); ?>" required />
                        </p>

                        <p>
                            <label for="guide_content_<?php echo esc_attr((string) $contribution->id); ?>">
                                <strong><?php echo esc_html__('Guide content', 'common-goals'); ?></strong>
                            </label>
                        </p>
                        <p>
                            <textarea id="guide_content_<?php echo esc_attr((string) $contribution->id); ?>" class="large-text" name="guide_content" rows="8" required><?php echo esc_textarea($contribution->body); ?></textarea>
                        </p>

                        <?php submit_button(__('Publish guide', 'common-goals'), 'primary', 'submit', false); ?>
                    </form>
                </details>
            </div>
        <?php endforeach; ?>
        </form>
    <?php else : ?>
        <p><?php echo esc_html__('No contributions have been created yet.', 'common-goals'); ?></p>
    <?php endif; ?>

    <hr />
    <h2><?php echo esc_html__('Export', 'common-goals'); ?></h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="common_goals_export" />
        <?php wp_nonce_field('common_goals_export'); ?>
        <p><?php echo esc_html__('Download a JSON export of all community data including communities, members, goals, contributions, responses, guides and events.', 'common-goals'); ?></p>
        <?php submit_button(__('Download JSON export', 'common-goals'), 'secondary', 'submit', false); ?>
    </form>
</div>
