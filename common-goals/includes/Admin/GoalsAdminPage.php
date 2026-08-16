<?php
/**
 * Admin page for managing community goals.
 *
 * @package CommonGoals
 */

namespace CommonGoals\Admin;

use CommonGoals\Capabilities;
use CommonGoals\Database;
use CommonGoals\Domain;
use CommonGoals\EventLogger;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Provides a minimal admin interface for MVP goal management.
 */
final class GoalsAdminPage
{
    /**
     * Registers admin hooks.
     */
    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_post_common_goals_create_goal', [$this, 'handle_create_goal']);
        add_action('admin_post_common_goals_update_goal', [$this, 'handle_update_goal']);
    }

    /**
     * Adds the Common Goals menu item.
     *
     * The top-level menu is only registered for users who can reach at least
     * one Common Goals admin area, so accounts without any community
     * assignment do not see an empty parent entry.
     */
    public function register_admin_menu(): void
    {
        if (! Domain::current_user_can_access_any_admin_area()) {
            return;
        }

        add_menu_page(
            __('Common Goals', 'common-goals'),
            __('Common Goals', 'common-goals'),
            'read',
            'common-goals',
            [$this, 'render_page'],
            'dashicons-groups',
            26
        );
    }

    /**
     * Saves a new community goal from the admin form.
     */
    public function handle_create_goal(): void
    {
        if (! Domain::current_user_can_access_goal_management()) {
            wp_die(esc_html__('You do not have permission to manage goals.', 'common-goals'));
        }

        check_admin_referer('common_goals_create_goal');

        global $wpdb;

        $now                        = current_time('mysql');
        $redirect_url               = wp_get_referer() ?: admin_url('admin.php?page=common-goals');
        $goals_table                = Database::goals_table();
        $community_id               = absint($_POST['community_id'] ?? 0);
        $title                      = sanitize_text_field(wp_unslash($_POST['goal_title'] ?? ''));
        $description                = wp_kses_post(wp_unslash($_POST['goal_description'] ?? ''));
        $beneficiary                = sanitize_text_field(wp_unslash($_POST['goal_beneficiary'] ?? ''));
        $alignment_rules            = wp_kses_post(wp_unslash($_POST['goal_alignment_rules'] ?? ''));

        $submitted_types = array_map('sanitize_key', (array) wp_unslash($_POST['goal_types'] ?? []));
        $allowed_contribution_types = array_values(array_intersect(Domain::CONTRIBUTION_TYPES, $submitted_types));
        if ($allowed_contribution_types === []) {
            $allowed_contribution_types = Domain::CONTRIBUTION_TYPES;
        }

        if ($community_id <= 0) {
            $community_id = Domain::get_default_community_id();
        }

        if (! Domain::get_community($community_id) || ! Domain::current_user_can_manage_community($community_id)) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_community', $redirect_url));
            exit;
        }

        if ($title === '' || $description === '') {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'missing_required_fields', $redirect_url));
            exit;
        }

        $inserted = $wpdb->insert(
            $goals_table,
            [
                'community_id'               => $community_id,
                'title'                      => $title,
                'description'                => $description,
                'beneficiary'                => $beneficiary,
                'allowed_contribution_types' => wp_json_encode($allowed_contribution_types),
                'alignment_rules'            => $alignment_rules,
                'status'                     => 'active',
                'created_by'                 => get_current_user_id(),
                'created_at'                 => $now,
                'updated_at'                 => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        if ($inserted === false) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'db_error', $redirect_url));
            exit;
        }

        $goal_id = (int) $wpdb->insert_id;

        EventLogger::log('goal', $goal_id, 'goal.created', ['title' => $title, 'community_id' => $community_id]);

        do_action('common_goals_goal_created', $goal_id, ['title' => $title, 'status' => 'active']);

        wp_safe_redirect(add_query_arg('common_goals_notice', 'goal_created', $redirect_url));
        exit;
    }

    /**
     * Updates an existing community goal from the admin form.
     */
    public function handle_update_goal(): void
    {
        if (! Domain::current_user_can_access_goal_management()) {
            wp_die(esc_html__('You do not have permission to manage goals.', 'common-goals'));
        }

        check_admin_referer('common_goals_update_goal');

        global $wpdb;

        $now             = current_time('mysql');
        $redirect_url    = wp_get_referer() ?: admin_url('admin.php?page=common-goals');
        $goal_id         = absint($_POST['goal_id'] ?? 0);
        $community_id    = absint($_POST['community_id'] ?? 0);
        $title           = sanitize_text_field(wp_unslash($_POST['goal_title'] ?? ''));
        $description     = wp_kses_post(wp_unslash($_POST['goal_description'] ?? ''));
        $beneficiary     = sanitize_text_field(wp_unslash($_POST['goal_beneficiary'] ?? ''));
        $alignment_rules            = wp_kses_post(wp_unslash($_POST['goal_alignment_rules'] ?? ''));

        $submitted_types = array_map('sanitize_key', (array) wp_unslash($_POST['goal_types'] ?? []));
        $allowed_contribution_types = array_values(array_intersect(Domain::CONTRIBUTION_TYPES, $submitted_types));
        if ($allowed_contribution_types === []) {
            $allowed_contribution_types = Domain::CONTRIBUTION_TYPES;
        }

        $status          = sanitize_key(wp_unslash($_POST['goal_status'] ?? 'active'));
        $allowed_statuses = Domain::GOAL_STATUSES;
        $goals_table     = Database::goals_table();

        $existing_goal = $wpdb->get_row($wpdb->prepare("SELECT community_id FROM {$goals_table} WHERE id = %d", $goal_id));

        if ($goal_id <= 0 || $community_id <= 0 || $title === '' || $description === '' || ! in_array($status, $allowed_statuses, true) || ! Domain::get_community($community_id) || ! $existing_goal || ! Domain::current_user_can_manage_community((int) $existing_goal->community_id) || ! Domain::current_user_can_manage_community($community_id)) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_goal', $redirect_url));
            exit;
        }

        $updated = $wpdb->update(
            $goals_table,
            [
                'community_id'               => $community_id,
                'title'                      => $title,
                'description'                => $description,
                'beneficiary'                => $beneficiary,
                'allowed_contribution_types' => wp_json_encode($allowed_contribution_types),
                'alignment_rules'            => $alignment_rules,
                'status'                     => $status,
                'updated_at'                 => $now,
            ],
            ['id' => $goal_id],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'db_error', $redirect_url));
            exit;
        }

        EventLogger::log('goal', $goal_id, 'goal.updated', [
            'title'  => $title,
            'community_id' => $community_id,
            'status' => $status,
        ]);

        do_action('common_goals_goal_updated', $goal_id, ['title' => $title, 'status' => $status]);

        wp_safe_redirect(add_query_arg('common_goals_notice', 'goal_updated', $redirect_url));
        exit;
    }

    /**
     * Renders the goals admin page.
     */
    public function render_page(): void
    {
        global $wpdb;

        $goals_table = Database::goals_table();
        $communities_table = Database::communities_table();
        $can_manage_all = current_user_can(Capabilities::MANAGE);
        $allowed_ids    = $can_manage_all ? [] : Domain::current_user_community_ids(['admin']);

        if (! $can_manage_all && $allowed_ids === []) {
            wp_die(esc_html__('You do not have permission to manage goals.', 'common-goals'));
        }

        $goals_scope       = $can_manage_all ? '1 = 1' : Domain::community_scope_sql('community_id', $allowed_ids);
        $communities_scope = $can_manage_all ? '1 = 1' : Domain::community_scope_sql('id', $allowed_ids);
        $goals       = $wpdb->get_results("SELECT * FROM {$goals_table} WHERE {$goals_scope} ORDER BY created_at DESC LIMIT 50");
        $communities = $wpdb->get_results("SELECT * FROM {$communities_table} WHERE {$communities_scope} ORDER BY name ASC");

        include COMMON_GOALS_PLUGIN_DIR . 'templates/admin-goals-page.php';
    }
}
