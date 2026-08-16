<?php
/**
 * Admin page for reviewing the event log.
 *
 * @package CommonGoals
 */

namespace CommonGoals\Admin;

use CommonGoals\Capabilities;
use CommonGoals\Database;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Surfaces recent community events for audit and future analytics.
 */
final class EventsAdminPage
{
    /**
     * Renders the events admin page.
     */
    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
    }

    /**
     * Adds the events submenu under Common Goals.
     */
    public function register_admin_menu(): void
    {
        if (! \CommonGoals\Domain::current_user_can_access_events()) {
            return;
        }

        add_submenu_page(
            'common-goals',
            __('Events', 'common-goals'),
            __('Events', 'common-goals'),
            'read',
            'common-goals-events',
            [$this, 'render_page']
        );
    }

    /**
     * Renders the events admin page.
     */
    public function render_page(): void
    {
        global $wpdb;

        /* Dependencies. */
        $events_table = Database::events_table();
        $selected_community = absint($_GET['community_id'] ?? 0);
        $can_view_all = current_user_can(Capabilities::VIEW_EVENTS);
        $allowed_ids  = $can_view_all ? [] : \CommonGoals\Domain::current_user_community_ids(['admin', 'moderator']);

        if (! $can_view_all && $allowed_ids === []) {
            wp_die(esc_html__('You do not have permission to view events.', 'common-goals'));
        }

        $where_clauses = [];

        if ($selected_community > 0) {
            if (! $can_view_all && ! in_array($selected_community, $allowed_ids, true)) {
                wp_die(esc_html__('You do not have permission to view this community.', 'common-goals'));
            }

            $where_clauses[] = $wpdb->prepare("event_data LIKE %s", '%"community_id":' . $selected_community . '%');
        } elseif (! $can_view_all) {
            $community_clauses = [];

            foreach ($allowed_ids as $community_id) {
                $community_clauses[] = $wpdb->prepare("event_data LIKE %s", '%"community_id":' . $community_id . '%');
            }

            $where_clauses[] = '(' . implode(' OR ', $community_clauses) . ')';
        }

        $where_sql = $where_clauses !== [] ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        /* Processing. */
        $events = $wpdb->get_results("SELECT * FROM {$events_table} {$where_sql} ORDER BY created_at DESC, id DESC LIMIT 100");
        $communities_scope = $can_view_all ? '1 = 1' : \CommonGoals\Domain::community_scope_sql('id', $allowed_ids);
        $communities = $wpdb->get_results('SELECT * FROM ' . Database::communities_table() . " WHERE {$communities_scope} ORDER BY name ASC");

        /* Result. */
        include COMMON_GOALS_PLUGIN_DIR . 'templates/admin-events-page.php';
    }
}
