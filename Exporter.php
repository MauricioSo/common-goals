<?php
/**
 * Structured JSON export for portability and future Cloud migration.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Produces a versioned JSON export of all community data so site owners
 * can back up, migrate or inspect their content without reverse-engineering
 * the schema.
 */
final class Exporter
{
    public const SCHEMA_VERSION = '1.0';

    /**
     * Builds the full export payload as an associative array.
     *
     * @return array<string, mixed>
     */
    public static function build_export(): array
    {
        global $wpdb;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'plugin_version' => COMMON_GOALS_VERSION,
            'exported_at'    => current_time('mysql'),
            'site_url'       => home_url(),
            'tables'         => [
                'communities'   => $wpdb->get_results('SELECT * FROM ' . Database::communities_table() . ' ORDER BY id', ARRAY_A),
                'members'       => $wpdb->get_results('SELECT * FROM ' . Database::community_members_table() . ' ORDER BY id', ARRAY_A),
                'goals'         => $wpdb->get_results('SELECT * FROM ' . Database::goals_table() . ' ORDER BY id', ARRAY_A),
                'contributions' => $wpdb->get_results('SELECT * FROM ' . Database::contributions_table() . ' ORDER BY id', ARRAY_A),
                'responses'     => $wpdb->get_results('SELECT * FROM ' . Database::responses_table() . ' ORDER BY id', ARRAY_A),
                'guides'        => $wpdb->get_results('SELECT * FROM ' . Database::guides_table() . ' ORDER BY id', ARRAY_A),
                'events'        => $wpdb->get_results('SELECT * FROM ' . Database::events_table() . ' ORDER BY id', ARRAY_A),
            ],
            'manifest' => self::build_manifest(),
        ];
    }

    /**
     * Returns the JSON string representation of the export.
     */
    public static function to_json(): string
    {
        return (string) wp_json_encode(self::build_export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Streams a JSON file download to the browser.
     */
    public static function download(): void
    {
        if (! current_user_can(Capabilities::MANAGE)) {
            wp_die(esc_html__('You do not have permission to export data.', 'common-goals'));
        }

        check_admin_referer('common_goals_export');

        $filename = 'common-goals-export-' . gmdate('Y-m-d-His') . '.json';

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo self::to_json();
        exit;
    }

    /**
     * Builds a manifest describing table counts and relationships.
     *
     * @return array<string, mixed>
     */
    private static function build_manifest(): array
    {
        global $wpdb;

        return [
            'schema_version'   => self::SCHEMA_VERSION,
            'table_counts'     => [
                'goals'         => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Database::goals_table()),
                'communities'   => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Database::communities_table()),
                'members'       => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Database::community_members_table()),
                'contributions' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Database::contributions_table()),
                'responses'     => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Database::responses_table()),
                'guides'        => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Database::guides_table()),
                'events'        => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Database::events_table()),
            ],
            'relationships'    => [
                'community_members.community_id' => 'communities.id',
                'community_members.user_id'      => 'users.ID',
                'goals.community_id'             => 'communities.id',
                'goals.beneficiary'             => 'string',
                'contributions.goal_id'          => 'goals.id',
                'contributions.user_id'          => 'users.ID (0 = anonymous)',
                'responses.contribution_id'      => 'contributions.id',
                'responses.user_id'              => 'users.ID (0 = anonymous)',
                'guides.contribution_id'         => 'contributions.id',
                'events.object_type'             => 'goal|contribution|response|guide',
                'events.object_id'               => 'related entity id',
                'events.created_by'              => 'users.ID (0 = system)',
            ],
            'allowed_values'   => [
                'contribution_types'    => Domain::CONTRIBUTION_TYPES,
                'contribution_statuses' => Domain::CONTRIBUTION_STATUSES,
                'guide_statuses'        => Domain::GUIDE_STATUSES,
                'goal_statuses'         => Domain::GOAL_STATUSES,
            ],
        ];
    }
}
