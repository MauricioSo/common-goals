<?php
/**
 * Database schema and table helpers.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Centralizes custom table names and schema creation.
 */
final class Database
{
    /**
     * Returns the full table name for communities.
     */
    public static function communities_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cg_communities';
    }

    /**
     * Returns the full table name for community members.
     */
    public static function community_members_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cg_community_members';
    }

    /**
     * Returns the full table name for community goals.
     */
    public static function goals_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cg_goals';
    }

    /**
     * Returns the full table name for community contributions.
     */
    public static function contributions_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cg_contributions';
    }

    /**
     * Returns the full table name for responses to contributions.
     */
    public static function responses_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cg_responses';
    }

    /**
     * Returns the full table name for living guides.
     */
    public static function guides_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cg_guides';
    }

    /**
     * Returns the full table name for audit events.
     */
    public static function events_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cg_events';
    }

    /**
     * Returns the full table name for votes (upvotes/downvotes).
     */
    public static function votes_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cg_votes';
    }

    /**
     * Returns the full table name for bookmarks (saved threads).
     */
    public static function bookmarks_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cg_bookmarks';
    }

    /**
     * Returns the full table name for user reports (flagged content).
     */
    public static function reports_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cg_reports';
    }

    /**
     * Returns the full table name for in-app notifications.
     */
    public static function notifications_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cg_notifications';
    }

    /**
     * Returns the full table name for AI assistant run auditing and budgeting.
     */
    public static function ai_runs_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cg_ai_runs';
    }

    /**
     * Creates all MVP custom tables.
     */
    public static function create_tables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate     = $wpdb->get_charset_collate();
        $communities_table   = self::communities_table();
        $members_table       = self::community_members_table();
        $goals_table         = self::goals_table();
        $contributions_table = self::contributions_table();
        $responses_table     = self::responses_table();
        $guides_table        = self::guides_table();
        $events_table        = self::events_table();
        $votes_table         = self::votes_table();
        $bookmarks_table     = self::bookmarks_table();
        $reports_table     = self::reports_table();
        $notifications_table = self::notifications_table();
        $ai_runs_table     = self::ai_runs_table();

        $schema = [];

        $schema[] = "CREATE TABLE {$communities_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            description longtext NOT NULL DEFAULT '',
            status varchar(30) NOT NULL DEFAULT 'active',
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY status (status)
        ) {$charset_collate};";

        $schema[] = "CREATE TABLE {$members_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            community_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            role varchar(30) NOT NULL DEFAULT 'member',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY community_user (community_id, user_id),
            KEY user_id (user_id)
        ) {$charset_collate};";

        $schema[] = "CREATE TABLE {$goals_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            community_id bigint(20) unsigned NOT NULL DEFAULT 1,
            title varchar(190) NOT NULL,
            description longtext NOT NULL,
            beneficiary varchar(190) NOT NULL DEFAULT '',
            allowed_contribution_types text NOT NULL,
            alignment_rules longtext NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'active',
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY community_id (community_id),
            KEY status (status)
        ) {$charset_collate};";

        $schema[] = "CREATE TABLE {$contributions_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            goal_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            type varchar(40) NOT NULL,
            status varchar(40) NOT NULL DEFAULT 'pending',
            score int NOT NULL DEFAULT 0,
            views int unsigned NOT NULL DEFAULT 0,
            is_sticky tinyint(1) unsigned NOT NULL DEFAULT 0,
            topic varchar(120) NOT NULL DEFAULT '',
            title varchar(190) NOT NULL,
            body longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY goal_id (goal_id),
            KEY type (type),
            KEY status (status),
            KEY goal_status_created (goal_id, status, created_at),
            KEY goal_status_score (goal_id, status, score)
        ) {$charset_collate};";

        $schema[] = "CREATE TABLE {$responses_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            contribution_id bigint(20) unsigned NOT NULL,
            parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            body longtext NOT NULL,
            status varchar(40) NOT NULL DEFAULT 'pending',
            score int NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY contribution_id (contribution_id),
            KEY parent_id (parent_id),
            KEY status (status),
            KEY contribution_status_created (contribution_id, status, created_at),
            KEY contribution_status_score (contribution_id, status, score)
        ) {$charset_collate};";

        $schema[] = "CREATE TABLE {$guides_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            contribution_id bigint(20) unsigned NOT NULL,
            slug varchar(190) NOT NULL,
            title varchar(190) NOT NULL,
            content longtext NOT NULL,
            status varchar(40) NOT NULL DEFAULT 'draft',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY contribution_id (contribution_id),
            KEY status (status),
            KEY status_updated (status, updated_at)
        ) {$charset_collate};";

        $schema[] = "CREATE TABLE {$events_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            object_type varchar(60) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_type varchar(80) NOT NULL,
            event_data longtext NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY object_lookup (object_type, object_id),
            KEY event_type (event_type),
            KEY created_at_id (created_at, id)
        ) {$charset_collate};";

        $schema[] = "CREATE TABLE {$votes_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            object_type varchar(20) NOT NULL,
            object_id bigint(20) unsigned NOT NULL,
            value tinyint NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_object (user_id, object_type, object_id),
            KEY object_lookup (object_type, object_id)
        ) {$charset_collate};";

        $schema[] = "CREATE TABLE {$bookmarks_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            contribution_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_contribution (user_id, contribution_id),
            KEY user_id (user_id)
        ) {$charset_collate};";

        $schema[] = "CREATE TABLE {$reports_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            object_type varchar(20) NOT NULL,
            object_id bigint(20) unsigned NOT NULL,
            reporter_id bigint(20) unsigned NOT NULL DEFAULT 0,
            reason varchar(60) NOT NULL DEFAULT '',
            detail longtext NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY object_lookup (object_type, object_id, status),
            KEY status (status)
        ) {$charset_collate};";

        $schema[] = "CREATE TABLE {$notifications_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            type varchar(40) NOT NULL,
            object_type varchar(20) NOT NULL DEFAULT '',
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            summary text NOT NULL,
            is_read tinyint(1) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_read (user_id, is_read, created_at),
            KEY user_id (user_id)
        ) {$charset_collate};";

        $schema[] = "CREATE TABLE {$ai_runs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            flow varchar(60) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            model varchar(60) NOT NULL DEFAULT '',
            prompt_tokens int unsigned NOT NULL DEFAULT 0,
            completion_tokens int unsigned NOT NULL DEFAULT 0,
            cost_usd decimal(12,6) NOT NULL DEFAULT 0,
            status varchar(30) NOT NULL DEFAULT 'success',
            error_code varchar(60) NOT NULL DEFAULT '',
            latency_ms int unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY flow_created (flow, created_at),
            KEY status_created (status, created_at),
            KEY user_id (user_id)
        ) {$charset_collate};";

        foreach ($schema as $table_schema) {
            dbDelta($table_schema);
        }
    }
}
