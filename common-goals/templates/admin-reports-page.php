<?php
/**
 * Admin template for reviewing Common Goals reports.
 *
 * @var array  $reports        Report rows with joined content.
 * @var array  $reason_labels  Reason code => label.
 *
 * @package CommonGoals
 */

if (! defined('ABSPATH')) {
    exit;
}

$status_labels = [
    'pending'   => __('Pending', 'common-goals'),
    'resolved'  => __('Resolved', 'common-goals'),
    'dismissed' => __('Dismissed', 'common-goals'),
];
?>

<div class="wrap">
    <h1><?php echo esc_html__('Common Goals Reports', 'common-goals'); ?></h1>

    <?php
    $notice_code = isset($_GET['common_goals_notice']) ? sanitize_key(wp_unslash($_GET['common_goals_notice'])) : '';
    if ($notice_code !== '') :
        $is_error = in_array($notice_code, ['invalid_report'], true);
        ?>
        <div class="notice <?php echo $is_error ? 'notice-error' : 'notice-success'; ?> is-dismissible">
            <p><?php echo esc_html($is_error ? __('An error occurred.', 'common-goals') : __('Action completed successfully.', 'common-goals')); ?></p>
        </div>
    <?php endif; ?>

    <?php if (! empty($reports)) : ?>
        <?php foreach ($reports as $report) :
            $is_contribution = $report->object_type === 'contribution';
            $title = $is_contribution ? ($report->contribution_title ?? '') : __('Response', 'common-goals');
            $body  = $is_contribution ? ($report->contribution_body ?? '') : ($report->response_body ?? '');
        ?>
            <div class="card" style="max-width: 980px; margin-top: 18px; padding: 18px;">
                <p>
                    <strong>#<?php echo esc_html((string) $report->id); ?></strong>
                    <span style="background:<?php echo $report->status === 'pending' ? '#c77f1c' : '#30445f'; ?>;color:#fff;border-radius:999px;font-size:11px;font-weight:700;padding:3px 9px;text-transform:uppercase;">
                        <?php echo esc_html($status_labels[$report->status] ?? ucfirst($report->status)); ?>
                    </span>
                    <strong><?php echo esc_html__('Reason:', 'common-goals'); ?></strong>
                    <?php echo esc_html($reason_labels[$report->reason] ?? ucfirst($report->reason)); ?>
                    <strong><?php echo esc_html__('Type:', 'common-goals'); ?></strong>
                    <?php echo esc_html($report->object_type); ?>
                    <strong><?php echo esc_html__('Reporter:', 'common-goals'); ?></strong>
                    <?php echo esc_html('#' . $report->reporter_id); ?>
                    <strong><?php echo esc_html__('Date:', 'common-goals'); ?></strong>
                    <?php echo esc_html($report->created_at); ?>
                </p>
                <p><strong><?php echo esc_html($title); ?></strong></p>
                <div><?php echo wp_kses_post(wpautop($body)); ?></div>
                <?php if (! empty($report->detail)) : ?>
                    <p style="color:#506176;font-size:13px;"><em><?php echo esc_html($report->detail); ?></em></p>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="align-items:center;display:flex;flex-wrap:wrap;gap:10px;margin-top:14px;">
                    <input type="hidden" name="action" value="common_goals_update_report" />
                    <input type="hidden" name="report_id" value="<?php echo esc_attr((string) $report->id); ?>" />
                    <?php wp_nonce_field('common_goals_update_report'); ?>
                    <select name="report_status">
                        <option value="pending"><?php echo esc_html__('Pending', 'common-goals'); ?></option>
                        <option value="resolved"><?php echo esc_html__('Resolved', 'common-goals'); ?></option>
                        <option value="dismissed"><?php echo esc_html(__('Dismissed', 'common-goals')); ?></option>
                    </select>
                    <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
                        <input type="checkbox" name="hide_content" value="1" /> <?php echo esc_html__('Hide content too', 'common-goals'); ?>
                    </label>
                    <?php submit_button(__('Update', 'common-goals'), 'secondary', 'submit', false); ?>
                </form>
            </div>
        <?php endforeach; ?>
    <?php else : ?>
        <p><?php echo esc_html__('No reports have been filed. Nice!', 'common-goals'); ?></p>
    <?php endif; ?>
</div>
