<?php
/**
 * Admin page for reviewing contributions and creating guides.
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
 * Provides moderation and manual guide creation for MVP contributions.
 */
final class ContributionsAdminPage
{
    /**
     * Registers admin hooks.
     */
    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_post_common_goals_create_guide', [$this, 'handle_create_guide']);
        add_action('admin_post_common_goals_update_contribution_status', [$this, 'handle_update_contribution_status']);
        add_action('admin_post_common_goals_bulk_moderate', [$this, 'handle_bulk_moderate']);
        add_action('admin_post_common_goals_update_response_status', [$this, 'handle_update_response_status']);
        add_action('admin_post_common_goals_toggle_sticky', [$this, 'handle_toggle_sticky']);
    }

    /**
     * Adds the contributions submenu under Common Goals.
     */
    public function register_admin_menu(): void
    {
        if (! Domain::current_user_can_access_moderation()) {
            return;
        }

        add_submenu_page(
            'common-goals',
            __('Contributions', 'common-goals'),
            __('Contributions', 'common-goals'),
            'read',
            'common-goals-contributions',
            [$this, 'render_page']
        );
    }

    /**
     * Creates a public guide from a contribution inside a transaction.
     */
    public function handle_create_guide(): void
    {
        if (! Domain::current_user_can_access_guides()) {
            wp_die(esc_html__('You do not have permission to moderate contributions.', 'common-goals'));
        }

        check_admin_referer('common_goals_create_guide');

        global $wpdb;

        $now             = current_time('mysql');
        $redirect_url    = wp_get_referer() ?: admin_url('admin.php?page=common-goals-contributions');
        $contribution_id = absint($_POST['contribution_id'] ?? 0);
        $guide_title     = sanitize_text_field(wp_unslash($_POST['guide_title'] ?? ''));
        $guide_content   = wp_kses_post(wp_unslash($_POST['guide_content'] ?? ''));

        if ($contribution_id <= 0 || $guide_title === '' || $guide_content === '') {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_guide', $redirect_url));
            exit;
        }

        $contribution = Domain::get_contribution($contribution_id);

        if (! $contribution) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'missing_contribution', $redirect_url));
            exit;
        }

        $community_id = $this->community_id_for_goal((int) $contribution->goal_id);

        if (! Domain::current_user_can_publish_guides_for_community($community_id)) {
            wp_die(esc_html__('You do not have permission to create guides for this community.', 'common-goals'));
        }

        $contributions_table = Database::contributions_table();
        $guides_table        = Database::guides_table();

        $existing_guide = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$guides_table} WHERE contribution_id = %d LIMIT 1", $contribution_id)
        );

        if ($existing_guide) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'guide_already_exists', $redirect_url));
            exit;
        }

        $wpdb->query('START TRANSACTION');

        $guide_slug = $this->create_unique_guide_slug($guide_title);

        $guide_inserted = $wpdb->insert(
            $guides_table,
            [
                'contribution_id' => $contribution_id,
                'slug'            => $guide_slug,
                'title'           => $guide_title,
                'content'         => $guide_content,
                'status'          => 'draft',
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($guide_inserted === false) {
            $wpdb->query('ROLLBACK');
            wp_safe_redirect(add_query_arg('common_goals_notice', 'db_error', $redirect_url));
            exit;
        }

        $guide_id = (int) $wpdb->insert_id;

        $contribution_updated = $wpdb->update(
            $contributions_table,
            [
                'status'     => 'resolved',
                'updated_at' => $now,
            ],
            ['id' => $contribution_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($contribution_updated === false) {
            $wpdb->query('ROLLBACK');
            wp_safe_redirect(add_query_arg('common_goals_notice', 'db_error', $redirect_url));
            exit;
        }

        EventLogger::log('guide', $guide_id, 'guide.created', [
            'contribution_id' => $contribution_id,
            'community_id'    => $this->community_id_for_goal((int) $contribution->goal_id),
        ]);

        wp_cache_delete('cg_guide_' . md5($guide_slug), 'common_goals');

        EventLogger::log('contribution', $contribution_id, 'contribution.status_changed', [
            'previous_status' => $contribution->status,
            'next_status'     => 'resolved',
            'reason'          => 'guide_created',
            'community_id'    => $this->community_id_for_goal((int) $contribution->goal_id),
        ]);

        $wpdb->query('COMMIT');

        wp_safe_redirect(add_query_arg('common_goals_notice', 'guide_created', $redirect_url));
        exit;
    }

    /**
     * Updates the moderation status for a contribution.
     */
    public function handle_update_contribution_status(): void
    {
        if (! Domain::current_user_can_access_moderation()) {
            wp_die(esc_html__('You do not have permission to moderate contributions.', 'common-goals'));
        }

        check_admin_referer('common_goals_update_contribution_status');

        global $wpdb;

        $now              = current_time('mysql');
        $redirect_url     = wp_get_referer() ?: admin_url('admin.php?page=common-goals-contributions');
        $contribution_id  = absint($_POST['contribution_id'] ?? 0);
        $next_status      = sanitize_key(wp_unslash($_POST['contribution_status'] ?? ''));

        if ($contribution_id <= 0 || ! in_array($next_status, Domain::CONTRIBUTION_STATUSES, true)) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_status', $redirect_url));
            exit;
        }

        $contributions_table = Database::contributions_table();

        $current_contribution = Domain::get_contribution($contribution_id);

        if (! $current_contribution) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'missing_contribution', $redirect_url));
            exit;
        }

        if (! Domain::current_user_can_moderate_community($this->community_id_for_goal((int) $current_contribution->goal_id))) {
            wp_die(esc_html__('You do not have permission to moderate this community.', 'common-goals'));
        }

        $updated = $wpdb->update(
            $contributions_table,
            [
                'status'     => $next_status,
                'updated_at' => $now,
            ],
            ['id' => $contribution_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'db_error', $redirect_url));
            exit;
        }

        EventLogger::log('contribution', $contribution_id, 'contribution.status_changed', [
            'previous_status' => $current_contribution->status,
            'next_status'     => $next_status,
            'reason'          => 'moderation',
            'community_id'    => $this->community_id_for_goal((int) $current_contribution->goal_id),
        ]);

        do_action('common_goals_contribution_status_changed', $contribution_id, $current_contribution->status, $next_status);

        wp_safe_redirect(add_query_arg('common_goals_notice', 'status_updated', $redirect_url));
        exit;
    }

    /**
     * Applies a moderation status to multiple contributions at once.
     */
    public function handle_bulk_moderate(): void
    {
        if (! Domain::current_user_can_access_moderation()) {
            wp_die(esc_html__('You do not have permission to moderate contributions.', 'common-goals'));
        }

        check_admin_referer('common_goals_bulk_moderate');

        global $wpdb;

        $now             = current_time('mysql');
        $redirect_url    = wp_get_referer() ?: admin_url('admin.php?page=common-goals-contributions');
        $next_status     = sanitize_key(wp_unslash($_POST['bulk_status'] ?? ''));
        $contribution_ids = array_map('absint', (array) wp_unslash($_POST['contribution_ids'] ?? []));
        $contribution_ids = array_filter($contribution_ids, static fn ($id) => $id > 0);

        if (! in_array($next_status, Domain::CONTRIBUTION_STATUSES, true) || $contribution_ids === []) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_bulk', $redirect_url));
            exit;
        }

        $contributions_table = Database::contributions_table();
        $ids_placeholder      = implode(',', array_fill(0, count($contribution_ids), '%d'));

        $contributions = $wpdb->get_results(
            $wpdb->prepare("SELECT id, goal_id, status FROM {$contributions_table} WHERE id IN ({$ids_placeholder})", ...$contribution_ids)
        );

        $updated_count = 0;

        foreach ($contributions as $contribution) {
            if (! Domain::current_user_can_moderate_community($this->community_id_for_goal((int) $contribution->goal_id))) {
                continue;
            }

            if (! Domain::is_valid_transition($contribution->status, $next_status)) {
                continue;
            }

            $result = $wpdb->update(
                $contributions_table,
                ['status' => $next_status, 'updated_at' => $now],
                ['id' => (int) $contribution->id],
                ['%s', '%s'],
                ['%d']
            );

            if ($result !== false) {
                $updated_count++;

                EventLogger::log('contribution', (int) $contribution->id, 'contribution.status_changed', [
                    'previous_status' => $contribution->status,
                    'next_status'     => $next_status,
                    'reason'          => 'bulk_moderation',
                    'community_id'    => $this->community_id_for_goal((int) $contribution->goal_id),
                ]);
            }
        }

        wp_safe_redirect(add_query_arg('common_goals_notice', 'bulk_done', $redirect_url));
        exit;
    }

    /**
     * Updates the moderation status of a single response.
     */
    public function handle_update_response_status(): void
    {
        if (! Domain::current_user_can_access_moderation()) {
            wp_die(esc_html__('You do not have permission to moderate responses.', 'common-goals'));
        }

        check_admin_referer('common_goals_update_response_status');

        global $wpdb;

        $now           = current_time('mysql');
        $redirect_url  = wp_get_referer() ?: admin_url('admin.php?page=common-goals-contributions');
        $response_id   = absint($_POST['response_id'] ?? 0);
        $next_status   = sanitize_key(wp_unslash($_POST['response_status'] ?? ''));

        if ($response_id <= 0 || ! in_array($next_status, Domain::response_statuses(), true)) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_status', $redirect_url));
            exit;
        }

        $responses_table = Database::responses_table();

        $current = $wpdb->get_row($wpdb->prepare("SELECT status, contribution_id FROM {$responses_table} WHERE id = %d", $response_id));

        if (! $current) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'missing_response', $redirect_url));
            exit;
        }

        if (! Domain::current_user_can_moderate_community($this->community_id_for_contribution((int) $current->contribution_id))) {
            wp_die(esc_html__('You do not have permission to moderate this community.', 'common-goals'));
        }

        $updated = $wpdb->update(
            $responses_table,
            ['status' => $next_status, 'updated_at' => $now],
            ['id' => $response_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'db_error', $redirect_url));
            exit;
        }

        EventLogger::log('response', $response_id, 'response.status_changed', [
            'previous_status' => $current->status,
            'next_status'     => $next_status,
            'reason'          => 'moderation',
            'community_id'    => $this->community_id_for_contribution((int) $current->contribution_id),
        ]);

        wp_safe_redirect(add_query_arg('common_goals_notice', 'status_updated', $redirect_url));
        exit;
    }

    /**
     * Pins or unpins a contribution so it floats to the top of the board.
     */
    public function handle_toggle_sticky(): void
    {
        if (! Domain::current_user_can_access_moderation()) {
            wp_die(esc_html__('You do not have permission to moderate contributions.', 'common-goals'));
        }

        check_admin_referer('common_goals_toggle_sticky');

        $redirect_url    = wp_get_referer() ?: admin_url('admin.php?page=common-goals-contributions');
        $contribution_id = absint($_POST['contribution_id'] ?? 0);
        $sticky          = (bool) absint($_POST['is_sticky'] ?? 0);

        if ($contribution_id <= 0) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'missing_contribution', $redirect_url));
            exit;
        }

        $contribution = Domain::get_contribution($contribution_id);

        if (! $contribution) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'missing_contribution', $redirect_url));
            exit;
        }

        if (! Domain::current_user_can_moderate_community($this->community_id_for_goal((int) $contribution->goal_id))) {
            wp_die(esc_html__('You do not have permission to moderate this community.', 'common-goals'));
        }

        Domain::set_sticky($contribution_id, $sticky);

        wp_safe_redirect(add_query_arg('common_goals_notice', $sticky ? 'sticky_pinned' : 'sticky_unpinned', $redirect_url));
        exit;
    }

    /**
     * Renders the contributions admin page.
     */
    public function render_page(): void
    {
        global $wpdb;

        $contributions_table = Database::contributions_table();
        $goals_table         = Database::goals_table();
        $communities_table   = Database::communities_table();
        $responses_table     = Database::responses_table();
        $selected_community  = absint($_GET['community_id'] ?? 0);
        $can_moderate_all    = current_user_can(Capabilities::MODERATE);
        $allowed_ids         = $can_moderate_all ? [] : Domain::current_user_community_ids(['admin', 'moderator']);

        if (! $can_moderate_all && $allowed_ids === []) {
            wp_die(esc_html__('You do not have permission to moderate contributions.', 'common-goals'));
        }

        $where_clauses = [];

        if ($selected_community > 0) {
            if (! $can_moderate_all && ! in_array($selected_community, $allowed_ids, true)) {
                wp_die(esc_html__('You do not have permission to moderate this community.', 'common-goals'));
            }

            $where_clauses[] = $wpdb->prepare('goals.community_id = %d', $selected_community);
        } elseif (! $can_moderate_all) {
            $where_clauses[] = Domain::community_scope_sql('goals.community_id', $allowed_ids);
        }

        $where_sql = $where_clauses !== [] ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $contributions = $wpdb->get_results(
            "SELECT contributions.*, goals.title AS goal_title, goals.community_id, communities.name AS community_name
            FROM {$contributions_table} contributions
            LEFT JOIN {$goals_table} goals ON goals.id = contributions.goal_id
            LEFT JOIN {$communities_table} communities ON communities.id = goals.community_id
            {$where_sql}
            ORDER BY contributions.created_at DESC
            LIMIT 50"
        );

        $communities_scope = $can_moderate_all ? '1 = 1' : Domain::community_scope_sql('id', $allowed_ids);
        $communities = $wpdb->get_results("SELECT * FROM {$communities_table} WHERE {$communities_scope} ORDER BY name ASC");

        $contribution_ids    = array_map('absint', wp_list_pluck($contributions, 'id'));
        $responses_by_cid    = [];

        if (! empty($contribution_ids)) {
            $ids_sql   = implode(',', $contribution_ids);
            $responses = $wpdb->get_results("SELECT * FROM {$responses_table} WHERE contribution_id IN ({$ids_sql}) ORDER BY created_at ASC");

            foreach ($responses as $response) {
                $cid = (int) $response->contribution_id;
                $responses_by_cid[$cid][] = $response;
            }
        }

        $allowed_statuses   = Domain::CONTRIBUTION_STATUSES;
        $response_statuses  = Domain::response_statuses();

        include COMMON_GOALS_PLUGIN_DIR . 'templates/admin-contributions-page.php';
    }

    /**
     * Creates a unique guide slug based on a readable title.
     */
    private function create_unique_guide_slug(string $guide_title): string
    {
        global $wpdb;

        $guides_table = Database::guides_table();
        $base_slug    = sanitize_title($guide_title);
        $base_slug    = $base_slug !== '' ? $base_slug : 'guide';
        $slug         = $base_slug;
        $suffix       = 2;

        while ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$guides_table} WHERE slug = %s LIMIT 1", $slug))) {
            $slug = $base_slug . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Finds the community ID for a goal.
     */
    private function community_id_for_goal(int $goal_id): int
    {
        global $wpdb;

        if ($goal_id <= 0) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare('SELECT community_id FROM ' . Database::goals_table() . ' WHERE id = %d', $goal_id));
    }

    /**
     * Finds the community ID for a contribution.
     */
    private function community_id_for_contribution(int $contribution_id): int
    {
        global $wpdb;

        if ($contribution_id <= 0) {
            return 0;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT goals.community_id FROM ' . Database::contributions_table() . ' contributions LEFT JOIN ' . Database::goals_table() . ' goals ON goals.id = contributions.goal_id WHERE contributions.id = %d',
                $contribution_id
            )
        );
    }
}
