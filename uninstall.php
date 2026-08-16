<?php
/**
 * Uninstall handler for Common Goals.
 *
 * By default, all community data is preserved to avoid accidental data loss.
 * If the site owner has enabled the explicit cleanup option, all custom
 * tables, options and capabilities are removed.
 *
 * @package CommonGoals
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$cleanup = (bool) get_option('common_goals_cleanup_on_uninstall', false);

if (! $cleanup) {
    return;
}

global $wpdb;

$tables = [
    $wpdb->prefix . 'cg_community_members',
    $wpdb->prefix . 'cg_communities',
    $wpdb->prefix . 'cg_goals',
    $wpdb->prefix . 'cg_contributions',
    $wpdb->prefix . 'cg_responses',
    $wpdb->prefix . 'cg_guides',
    $wpdb->prefix . 'cg_events',
    $wpdb->prefix . 'cg_votes',
    $wpdb->prefix . 'cg_bookmarks',
    $wpdb->prefix . 'cg_reports',
    $wpdb->prefix . 'cg_notifications',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

delete_option('common_goals_database_version');
delete_option('common_goals_cleanup_on_uninstall');

if (function_exists('get_role')) {
    foreach (['administrator', 'editor'] as $role_name) {
        $role = get_role($role_name);

        if (! $role) {
            continue;
        }

        $role->remove_cap('manage_common_goals');
        $role->remove_cap('moderate_common_goals');
        $role->remove_cap('publish_common_goals_guides');
        $role->remove_cap('view_common_goals_events');
    }

    remove_role('common_goals_moderator');
}
