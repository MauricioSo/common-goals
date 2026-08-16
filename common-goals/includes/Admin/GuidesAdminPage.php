<?php
/**
 * Admin page for editing living guides.
 *
 * @package CommonGoals
 */

namespace CommonGoals\Admin;

use CommonGoals\Capabilities;
use CommonGoals\Database;
use CommonGoals\Domain;
use CommonGoals\EventLogger;
use CommonGoals\Frontend\GuideRouter;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Provides basic guide editing after a contribution becomes reusable knowledge.
 */
final class GuidesAdminPage
{
    /**
     * Registers admin hooks.
     */
    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_post_common_goals_update_guide', [$this, 'handle_update_guide']);
    }

    /**
     * Adds the guides submenu under Common Goals.
     */
    public function register_admin_menu(): void
    {
        if (! Domain::current_user_can_access_guides()) {
            return;
        }

        add_submenu_page(
            'common-goals',
            __('Guides', 'common-goals'),
            __('Guides', 'common-goals'),
            'read',
            'common-goals-guides',
            [$this, 'render_page']
        );
    }

    /**
     * Updates title, slug, content, and status for a guide.
     */
    public function handle_update_guide(): void
    {
        if (! Domain::current_user_can_access_guides()) {
            wp_die(esc_html__('You do not have permission to edit guides.', 'common-goals'));
        }

        check_admin_referer('common_goals_update_guide');

        global $wpdb;

        $now              = current_time('mysql');
        $redirect_url     = wp_get_referer() ?: admin_url('admin.php?page=common-goals-guides');
        $guide_id         = absint($_POST['guide_id'] ?? 0);
        $guide_title      = sanitize_text_field(wp_unslash($_POST['guide_title'] ?? ''));
        $requested_slug   = sanitize_title(wp_unslash($_POST['guide_slug'] ?? ''));
        $guide_content    = wp_kses_post(wp_unslash($_POST['guide_content'] ?? ''));
        $guide_status     = sanitize_key(wp_unslash($_POST['guide_status'] ?? 'draft'));
        $allowed_statuses = Domain::GUIDE_STATUSES;
        $guides_table     = Database::guides_table();

        if ($guide_id <= 0 || $guide_title === '' || $guide_content === '' || ! in_array($guide_status, $allowed_statuses, true)) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_guide', $redirect_url));
            exit;
        }

        if (! Domain::current_user_can_publish_guides_for_community($this->community_id_for_guide($guide_id))) {
            wp_die(esc_html__('You do not have permission to edit guides for this community.', 'common-goals'));
        }

        $guide_slug = $this->create_unique_guide_slug($requested_slug !== '' ? $requested_slug : $guide_title, $guide_id);

        $updated = $wpdb->update(
            $guides_table,
            [
                'slug'       => $guide_slug,
                'title'      => $guide_title,
                'content'    => $guide_content,
                'status'     => $guide_status,
                'updated_at' => $now,
            ],
            ['id' => $guide_id],
            ['%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'db_error', $redirect_url));
            exit;
        }

        wp_cache_delete('cg_guide_' . md5($guide_slug), 'common_goals');
        wp_cache_delete('cg_guide_' . md5(sanitize_title($guide_title)), 'common_goals');

        EventLogger::log('guide', $guide_id, 'guide.updated', [
            'title'        => $guide_title,
            'slug'         => $guide_slug,
            'status'       => $guide_status,
            'community_id' => $this->community_id_for_guide($guide_id),
        ]);

        do_action('common_goals_guide_updated', $guide_id, ['title' => $guide_title, 'slug' => $guide_slug, 'status' => $guide_status]);

        wp_safe_redirect(add_query_arg('common_goals_notice', 'guide_updated', $redirect_url));
        exit;
    }

    /**
     * Renders the guides admin page.
     */
    public function render_page(): void
    {
        global $wpdb;

        $guides_table     = Database::guides_table();
        $contributions_table = Database::contributions_table();
        $goals_table      = Database::goals_table();
        $communities_table = Database::communities_table();
        $allowed_statuses = Domain::GUIDE_STATUSES;
        $selected_community = absint($_GET['community_id'] ?? 0);
        $can_publish_all = current_user_can(Capabilities::PUBLISH_GUIDES);
        $allowed_ids     = $can_publish_all ? [] : Domain::current_user_community_ids(['admin', 'moderator']);

        if (! $can_publish_all && $allowed_ids === []) {
            wp_die(esc_html__('You do not have permission to edit guides.', 'common-goals'));
        }

        $where_clauses = [];

        if ($selected_community > 0) {
            if (! $can_publish_all && ! in_array($selected_community, $allowed_ids, true)) {
                wp_die(esc_html__('You do not have permission to edit guides for this community.', 'common-goals'));
            }

            $where_clauses[] = $wpdb->prepare('goals.community_id = %d', $selected_community);
        } elseif (! $can_publish_all) {
            $where_clauses[] = Domain::community_scope_sql('goals.community_id', $allowed_ids);
        }

        $where_sql = $where_clauses !== [] ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $guides = $wpdb->get_results(
            "SELECT guides.*, goals.community_id, communities.name AS community_name
            FROM {$guides_table} guides
            LEFT JOIN {$contributions_table} contributions ON contributions.id = guides.contribution_id
            LEFT JOIN {$goals_table} goals ON goals.id = contributions.goal_id
            LEFT JOIN {$communities_table} communities ON communities.id = goals.community_id
            {$where_sql}
            ORDER BY guides.updated_at DESC
            LIMIT 50"
        );
        $communities_scope = $can_publish_all ? '1 = 1' : Domain::community_scope_sql('id', $allowed_ids);
        $communities = $wpdb->get_results("SELECT * FROM {$communities_table} WHERE {$communities_scope} ORDER BY name ASC");

        include COMMON_GOALS_PLUGIN_DIR . 'templates/admin-guides-page.php';
    }

    /**
     * Creates a unique guide slug while allowing the current guide to keep its slug.
     */
    private function create_unique_guide_slug(string $slug_source, int $current_guide_id): string
    {
        global $wpdb;

        $guides_table = Database::guides_table();
        $base_slug    = sanitize_title($slug_source);
        $base_slug    = $base_slug !== '' ? $base_slug : 'guide';
        $slug         = $base_slug;
        $suffix       = 2;

        while ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$guides_table} WHERE slug = %s AND id != %d LIMIT 1", $slug, $current_guide_id))) {
            $slug = $base_slug . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Finds the community ID for a guide.
     */
    private function community_id_for_guide(int $guide_id): int
    {
        global $wpdb;

        if ($guide_id <= 0) {
            return 0;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT goals.community_id FROM ' . Database::guides_table() . ' guides LEFT JOIN ' . Database::contributions_table() . ' contributions ON contributions.id = guides.contribution_id LEFT JOIN ' . Database::goals_table() . ' goals ON goals.id = contributions.goal_id WHERE guides.id = %d',
                $guide_id
            )
        );
    }
}
