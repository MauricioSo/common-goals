<?php
/**
 * Admin page for managing communities and members.
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
 * Provides CRUD for communities and basic member management.
 */
final class CommunitiesAdminPage
{
    /**
     * Registers admin hooks.
     */
    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_post_common_goals_create_community', [$this, 'handle_create_community']);
        add_action('admin_post_common_goals_update_community', [$this, 'handle_update_community']);
        add_action('admin_post_common_goals_add_member', [$this, 'handle_add_member']);
        add_action('admin_post_common_goals_remove_member', [$this, 'handle_remove_member']);
    }

    /**
     * Adds the communities submenu under Common Goals.
     */
    public function register_admin_menu(): void
    {
        if (! Domain::current_user_can_access_communities()) {
            return;
        }

        add_submenu_page(
            'common-goals',
            __('Communities', 'common-goals'),
            __('Communities', 'common-goals'),
            'read',
            'common-goals-communities',
            [$this, 'render_page']
        );
    }

    /**
     * Saves a new community.
     */
    public function handle_create_community(): void
    {
        if (! current_user_can(Capabilities::MANAGE)) {
            wp_die(esc_html__('You do not have permission to manage communities.', 'common-goals'));
        }

        check_admin_referer('common_goals_create_community');

        global $wpdb;

        $now         = current_time('mysql');
        $redirect_url = wp_get_referer() ?: admin_url('admin.php?page=common-goals-communities');
        $name        = sanitize_text_field(wp_unslash($_POST['community_name'] ?? ''));
        $description = wp_kses_post(wp_unslash($_POST['community_description'] ?? ''));
        $slug        = sanitize_title($name);

        if ($name === '') {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_community', $redirect_url));
            exit;
        }

        $table = Database::communities_table();

        $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE slug = %s", $slug));

        if ($existing > 0) {
            $slug = $slug . '-' . wp_rand(100, 999);
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'name'        => $name,
                'slug'        => $slug,
                'description' => $description,
                'status'      => 'active',
                'created_by'  => get_current_user_id(),
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        if ($inserted === false) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'db_error', $redirect_url));
            exit;
        }

        $community_id = (int) $wpdb->insert_id;

        EventLogger::log('community', $community_id, 'community.created', ['name' => $name]);

        do_action('common_goals_community_created', $community_id, ['name' => $name]);

        wp_safe_redirect(add_query_arg('common_goals_notice', 'community_created', $redirect_url));
        exit;
    }

    /**
     * Updates an existing community.
     */
    public function handle_update_community(): void
    {
        check_admin_referer('common_goals_update_community');

        global $wpdb;

        $now          = current_time('mysql');
        $redirect_url = wp_get_referer() ?: admin_url('admin.php?page=common-goals-communities');
        $community_id = absint($_POST['community_id'] ?? 0);
        $name         = sanitize_text_field(wp_unslash($_POST['community_name'] ?? ''));
        $description  = wp_kses_post(wp_unslash($_POST['community_description'] ?? ''));
        $status       = sanitize_key(wp_unslash($_POST['community_status'] ?? 'active'));

        if ($community_id <= 0 || $name === '' || ! in_array($status, ['active', 'inactive'], true)) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_community', $redirect_url));
            exit;
        }

        if (! Domain::current_user_can_manage_community($community_id)) {
            wp_die(esc_html__('You do not have permission to manage this community.', 'common-goals'));
        }

        $table   = Database::communities_table();
        $updated = $wpdb->update(
            $table,
            [
                'name'        => $name,
                'description' => $description,
                'status'      => $status,
                'updated_at'  => $now,
            ],
            ['id' => $community_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'db_error', $redirect_url));
            exit;
        }

        wp_safe_redirect(add_query_arg('common_goals_notice', 'community_updated', $redirect_url));
        exit;
    }

    /**
     * Adds a member to a community.
     */
    public function handle_add_member(): void
    {
        check_admin_referer('common_goals_add_member');

        global $wpdb;

        $redirect_url = wp_get_referer() ?: admin_url('admin.php?page=common-goals-communities');
        $community_id = absint($_POST['community_id'] ?? 0);
        $user_id      = absint($_POST['user_id'] ?? 0);
        $role         = sanitize_key(wp_unslash($_POST['member_role'] ?? 'member'));

        if ($community_id <= 0 || $user_id <= 0 || ! in_array($role, ['admin', 'moderator', 'member'], true)) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_member', $redirect_url));
            exit;
        }

        if (! Domain::current_user_can_manage_community($community_id)) {
            wp_die(esc_html__('You do not have permission to manage this community.', 'common-goals'));
        }

        $table = Database::community_members_table();

        $wpdb->replace(
            $table,
            [
                'community_id' => $community_id,
                'user_id'      => $user_id,
                'role'         => $role,
                'created_at'   => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s']
        );

        wp_safe_redirect(add_query_arg('common_goals_notice', 'member_added', $redirect_url));
        exit;
    }

    /**
     * Removes a member from a community.
     */
    public function handle_remove_member(): void
    {
        check_admin_referer('common_goals_remove_member');

        global $wpdb;

        $redirect_url = wp_get_referer() ?: admin_url('admin.php?page=common-goals-communities');
        $community_id = absint($_POST['community_id'] ?? 0);
        $user_id      = absint($_POST['user_id'] ?? 0);

        if ($community_id <= 0 || $user_id <= 0) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_member', $redirect_url));
            exit;
        }

        if (! Domain::current_user_can_manage_community($community_id)) {
            wp_die(esc_html__('You do not have permission to manage this community.', 'common-goals'));
        }

        $table = Database::community_members_table();

        $wpdb->delete($table, ['community_id' => $community_id, 'user_id' => $user_id], ['%d', '%d']);

        wp_safe_redirect(add_query_arg('common_goals_notice', 'member_removed', $redirect_url));
        exit;
    }

    /**
     * Renders the communities admin page.
     */
    public function render_page(): void
    {
        global $wpdb;

        $communities_table = Database::communities_table();
        $members_table     = Database::community_members_table();
        $goals_table       = Database::goals_table();

        $can_manage_all = current_user_can(Capabilities::MANAGE);
        $allowed_ids    = $can_manage_all ? [] : Domain::current_user_community_ids(['admin']);

        if (! $can_manage_all && $allowed_ids === []) {
            wp_die(esc_html__('You do not have permission to manage communities.', 'common-goals'));
        }

        $scope_sql   = $can_manage_all ? '1 = 1' : Domain::community_scope_sql('id', $allowed_ids);
        $communities = $wpdb->get_results("SELECT * FROM {$communities_table} WHERE {$scope_sql} ORDER BY id ASC");

        foreach ($communities as $community) {
            $community->goal_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$goals_table} WHERE community_id = %d", $community->id));
            $community->members    = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$members_table} WHERE community_id = %d ORDER BY role ASC", $community->id));
        }

        include COMMON_GOALS_PLUGIN_DIR . 'templates/admin-communities-page.php';
    }
}
