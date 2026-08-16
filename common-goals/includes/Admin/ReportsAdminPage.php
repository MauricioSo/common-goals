<?php
/**
 * Admin page for reviewing user reports.
 *
 * @package CommonGoals
 */

namespace CommonGoals\Admin;

use CommonGoals\Capabilities;
use CommonGoals\Database;
use CommonGoals\Domain;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Lists flagged content so moderators can resolve or dismiss reports.
 */
final class ReportsAdminPage
{
    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_post_common_goals_update_report', [$this, 'handle_update_report']);
    }

    public function register_admin_menu(): void
    {
        if (! Domain::current_user_can_access_moderation()) {
            return;
        }

        add_submenu_page(
            'common-goals',
            __('Reports', 'common-goals'),
            __('Reports', 'common-goals'),
            'read',
            'common-goals-reports',
            [$this, 'render_page']
        );
    }

    /**
     * Resolves or dismisses a report.
     */
    public function handle_update_report(): void
    {
        if (! Domain::current_user_can_access_moderation()) {
            wp_die(esc_html__('You do not have permission to review reports.', 'common-goals'));
        }

        check_admin_referer('common_goals_update_report');

        global $wpdb;

        $redirect_url = wp_get_referer() ?: admin_url('admin.php?page=common-goals-reports');
        $report_id    = absint($_POST['report_id'] ?? 0);
        $next_status  = sanitize_key(wp_unslash($_POST['report_status'] ?? ''));
        $hide_content = absint($_POST['hide_content'] ?? 0);

        if ($report_id <= 0 || ! in_array($next_status, Domain::REPORT_STATUSES, true)) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_report', $redirect_url));
            exit;
        }

        $reports_table = Database::reports_table();
        $report        = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$reports_table} WHERE id = %d", $report_id));

        if (! $report) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_report', $redirect_url));
            exit;
        }

        $wpdb->update($reports_table, ['status' => $next_status], ['id' => $report_id], ['%s'], ['%d']);

        // Optionally hide the reported content when resolving.
        if ($hide_content && $next_status === 'resolved') {
            if ($report->object_type === 'contribution') {
                $wpdb->update(Database::contributions_table(), ['status' => 'hidden'], ['id' => (int) $report->object_id], ['%s'], ['%d']);
            } elseif ($report->object_type === 'response') {
                $wpdb->update(Database::responses_table(), ['status' => 'hidden'], ['id' => (int) $report->object_id], ['%s'], ['%d']);
            }
        }

        \CommonGoals\EventLogger::log('report', $report_id, 'report.resolved', [
            'status' => $next_status,
            'object_type' => $report->object_type,
            'object_id' => (int) $report->object_id,
            'hidden' => $hide_content ? 1 : 0,
        ]);

        wp_safe_redirect(add_query_arg('common_goals_notice', 'status_updated', $redirect_url));
        exit;
    }

    public function render_page(): void
    {
        global $wpdb;

        $reports_table     = Database::reports_table();
        $contributions_tbl = Database::contributions_table();
        $responses_tbl     = Database::responses_table();

        $reports = $wpdb->get_results(
            "SELECT r.*,
                c.title AS contribution_title, c.body AS contribution_body,
                resp.body AS response_body
            FROM {$reports_table} r
            LEFT JOIN {$contributions_tbl} c ON c.id = r.object_id AND r.object_type = 'contribution'
            LEFT JOIN {$responses_tbl} resp ON resp.id = r.object_id AND r.object_type = 'response'
            ORDER BY FIELD(r.status, 'pending', 'resolved', 'dismissed'), r.created_at DESC
            LIMIT 100"
        );

        $reason_labels = [
            'spam'       => __('Spam or scam', 'common-goals'),
            'harassment' => __('Harassment', 'common-goals'),
            'off_topic'  => __('Off topic', 'common-goals'),
            'other'      => __('Other', 'common-goals'),
        ];

        include COMMON_GOALS_PLUGIN_DIR . 'templates/admin-reports-page.php';
    }
}
