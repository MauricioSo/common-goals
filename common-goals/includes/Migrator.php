<?php
/**
 * Versioned, idempotent database migration runner.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Compares the stored schema version against the plugin version and runs
 * incremental migrations only when needed. Safe to call on every request.
 */
final class Migrator
{
    public const OPTION_NAME = 'common_goals_database_version';

    /**
     * Runs pending migrations if the schema version is behind the plugin.
     */
    public static function run(): void
    {
        $installed_version = get_option(self::OPTION_NAME, '0');

        if (version_compare($installed_version, COMMON_GOALS_VERSION, '>=')) {
            return;
        }

        $migrations = self::migration_map();

        foreach ($migrations as $version => $callback) {
            if (version_compare($installed_version, $version, '<')) {
                $callback();
            }
        }

        update_option(self::OPTION_NAME, COMMON_GOALS_VERSION);
    }

    /**
     * Returns the ordered map of versioned migrations.
     *
     * @return array<string, callable>
     */
    private static function migration_map(): array
    {
        return [
            '0.1.0' => [self::class, 'migration_0_1_0_create_tables'],
            '0.2.0' => [self::class, 'migration_0_2_0_add_indices'],
            '0.9.0' => [self::class, 'migration_0_9_0_communities'],
            '1.2.0' => [self::class, 'migration_1_2_0_forum_features'],
            '1.3.0' => [self::class, 'migration_1_3_0_views'],
            '1.4.0' => [self::class, 'migration_1_4_0_sticky'],
            '1.7.0' => [self::class, 'migration_1_7_0_bookmarks'],
            '1.8.0' => [self::class, 'migration_1_8_0_reports'],
            '1.9.0' => [self::class, 'migration_1_9_0_notifications'],
            '2.0.0' => [self::class, 'migration_2_0_0_ai_runs'],
        ];
    }

    /**
     * Creates the initial set of custom tables.
     */
    private static function migration_0_1_0_create_tables(): void
    {
        Database::create_tables();
    }

    /**
     * Adds compound indices that match real query patterns.
     */
    private static function migration_0_2_0_add_indices(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $contributions = Database::contributions_table();
        $responses     = Database::responses_table();
        $guides        = Database::guides_table();
        $events        = Database::events_table();

        $wpdb->query("ALTER TABLE {$contributions} ADD INDEX goal_status_created (goal_id, status, created_at)");
        $wpdb->query("ALTER TABLE {$responses} ADD INDEX contribution_status_created (contribution_id, status, created_at)");
        $wpdb->query("ALTER TABLE {$guides} ADD INDEX status_updated (status, updated_at)");
        $wpdb->query("ALTER TABLE {$events} ADD INDEX created_at_id (created_at, id)");
    }

    /**
     * Adds the communities and community_members tables, adds
     * community_id to goals, and creates a default community.
     */
    private static function migration_0_9_0_communities(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate   = $wpdb->get_charset_collate();
        $communities_table = Database::communities_table();
        $members_table     = Database::community_members_table();
        $goals_table       = Database::goals_table();

        dbDelta("CREATE TABLE {$communities_table} (
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
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$members_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            community_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            role varchar(30) NOT NULL DEFAULT 'member',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY community_user (community_id, user_id),
            KEY user_id (user_id)
        ) {$charset_collate};");

        $has_community_id = $wpdb->get_var("SHOW COLUMNS FROM {$goals_table} LIKE 'community_id'");

        if (! $has_community_id) {
            $wpdb->query("ALTER TABLE {$goals_table} ADD COLUMN community_id bigint(20) unsigned NOT NULL DEFAULT 1 AFTER id");
        }

        $has_community_index = $wpdb->get_var("SHOW INDEX FROM {$goals_table} WHERE Key_name = 'community_id'");

        if (! $has_community_index) {
            $wpdb->query("ALTER TABLE {$goals_table} ADD INDEX community_id (community_id)");
        }

        $existing = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$communities_table}");

        if ($existing === 0) {
            $now  = current_time('mysql');
            $name = get_bloginfo('name') ?: 'Default Community';

            $wpdb->insert(
                $communities_table,
                [
                    'name'        => $name,
                    'slug'        => sanitize_title($name) ?: 'default',
                    'description' => '',
                    'status'      => 'active',
                    'created_by'  => get_current_user_id(),
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                ['%s', '%s', '%s', '%s', '%d', '%s', '%s']
            );
        }
    }

    /**
     * Adds forum features: a votes table, a score column on contributions
     * and responses, and a parent_id column on responses for threaded replies.
     */
    private static function migration_1_2_0_forum_features(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $contributions   = Database::contributions_table();
        $responses       = Database::responses_table();
        $votes           = Database::votes_table();

        dbDelta("CREATE TABLE {$votes} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            object_type varchar(20) NOT NULL,
            object_id bigint(20) unsigned NOT NULL,
            value tinyint NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_object (user_id, object_type, object_id),
            KEY object_lookup (object_type, object_id)
        ) {$charset_collate};");

        self::add_column_if_missing($contributions, 'score', "ADD COLUMN score int NOT NULL DEFAULT 0 AFTER status");
        self::add_column_if_missing($responses, 'score', "ADD COLUMN score int NOT NULL DEFAULT 0 AFTER status");
        self::add_column_if_missing($responses, 'parent_id', "ADD COLUMN parent_id bigint(20) unsigned NOT NULL DEFAULT 0 AFTER contribution_id");

        self::add_index_if_missing($contributions, 'goal_status_score', '(goal_id, status, score)');
        self::add_index_if_missing($responses, 'contribution_status_score', '(contribution_id, status, score)');
        self::add_index_if_missing($responses, 'parent_id', '(parent_id)');
    }

    /**
     * Adds a views counter column to contributions for popularity ranking.
     */
    private static function migration_1_3_0_views(): void
    {
        global $wpdb;

        $contributions = Database::contributions_table();

        self::add_column_if_missing($contributions, 'views', "ADD COLUMN views int unsigned NOT NULL DEFAULT 0 AFTER score");
    }

    /**
     * Adds an is_sticky flag to contributions so moderators can pin threads.
     */
    private static function migration_1_4_0_sticky(): void
    {
        global $wpdb;

        $contributions = Database::contributions_table();

        self::add_column_if_missing($contributions, 'is_sticky', "ADD COLUMN is_sticky tinyint(1) unsigned NOT NULL DEFAULT 0 AFTER views");
    }

    /**
     * Creates the bookmarks table for saved threads.
     */
    private static function migration_1_7_0_bookmarks(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $bookmarks       = Database::bookmarks_table();

        dbDelta("CREATE TABLE {$bookmarks} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            contribution_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_contribution (user_id, contribution_id),
            KEY user_id (user_id)
        ) {$charset_collate};");
    }

    /**
     * Creates the reports table for flagged content.
     */
    private static function migration_1_8_0_reports(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $reports         = Database::reports_table();

        dbDelta("CREATE TABLE {$reports} (
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
        ) {$charset_collate};");
    }

    /**
     * Creates the in-app notifications table.
     */
    private static function migration_1_9_0_notifications(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $notifications   = Database::notifications_table();

        dbDelta("CREATE TABLE {$notifications} (
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
        ) {$charset_collate};");
    }

    /**
     * Creates the AI assistant run table used for budget tracking and auditing.
     */
    private static function migration_2_0_0_ai_runs(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $ai_runs         = Database::ai_runs_table();

        dbDelta("CREATE TABLE {$ai_runs} (
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
        ) {$charset_collate};");
    }

    /**
     * Adds a column only when it does not already exist (idempotent).
     */
    private static function add_column_if_missing(string $table, string $column, string $definition): void
    {
        global $wpdb;

        $exists = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE '{$column}'");

        if (! $exists) {
            $wpdb->query("ALTER TABLE {$table} {$definition}");
        }
    }

    /**
     * Adds an index only when it does not already exist (idempotent).
     */
    private static function add_index_if_missing(string $table, string $name, string $columns): void
    {
        global $wpdb;

        $exists = $wpdb->get_var("SHOW INDEX FROM {$table} WHERE Key_name = '{$name}'");

        if (! $exists) {
            $wpdb->query("ALTER TABLE {$table} ADD INDEX {$name} {$columns}");
        }
    }
}
