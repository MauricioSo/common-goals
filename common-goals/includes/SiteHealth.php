<?php
/**
 * Site Health integration and event log retention.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers WordPress Site Health tests and a cron-based event log
 * retention routine so operators can detect problems and control
 * storage growth.
 */
final class SiteHealth
{
    public const RETENTION_OPTION    = 'common_goals_event_retention_days';
    public const CRON_HOOK           = 'common_goals_cleanup_events';
    public const CRON_INTERVAL_NAME  = 'common_goals_daily';
    public const DEFAULT_RETENTION   = 90;

    /**
     * Registers WordPress hooks.
     */
    public static function register_hooks(): void
    {
        add_filter('site_status_tests', [self::class, 'register_tests']);
        add_filter('debug_information', [self::class, 'register_debug_info']);
        add_action(self::CRON_HOOK, [self::class, 'cleanup_old_events']);
        add_action('cron_schedules', [self::class, 'register_cron_interval']);
    }

    /**
     * Adds Common Goals tests to the Site Health screen.
     *
     * @param array<string, mixed> $tests Existing tests.
     * @return array<string, mixed>
     */
    public static function register_tests(array $tests): array
    {
        $tests['direct']['common_goals_tables'] = [
            'label' => __('Common Goals database tables', 'common-goals'),
            'test'  => [self::class, 'test_tables_exist'],
        ];

        $tests['direct']['common_goals_schema'] = [
            'label' => __('Common Goals schema version', 'common-goals'),
            'test'  => [self::class, 'test_schema_version'],
        ];

        return $tests;
    }

    /**
     * Checks that all custom tables exist in the database.
     *
     * @return array<string, mixed>
     */
    public static function test_tables_exist(): array
    {
        global $wpdb;

        $result = [
            'label'       => __('Common Goals tables are present', 'common-goals'),
            'status'      => 'good',
            'badge'       => ['label' => __('Common Goals', 'common-goals'), 'color' => 'green'],
            'description' => sprintf('<p>%s</p>', esc_html__('All custom tables were found in the database.', 'common-goals')),
            'test'        => 'common_goals_tables',
        ];

        $tables = [
            Database::communities_table(),
            Database::community_members_table(),
            Database::goals_table(),
            Database::contributions_table(),
            Database::responses_table(),
            Database::guides_table(),
            Database::events_table(),
        ];

        $missing = [];

        foreach ($tables as $table) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

            if ($exists !== $table) {
                $missing[] = $table;
            }
        }

        if (! empty($missing)) {
            $result['status']      = 'critical';
            $result['label']       = __('Common Goals tables are missing', 'common-goals');
            $result['badge']['color'] = 'red';
            $result['description'] = sprintf('<p>%s: %s</p>', esc_html__('The following tables are missing', 'common-goals'), esc_html(implode(', ', $missing)));
            $result['actions']     = sprintf('<a href="%s">%s</a>', esc_url(admin_url('plugins.php')), esc_html__('Reactivate the plugin', 'common-goals'));
        }

        return $result;
    }

    /**
     * Checks that the stored schema version matches the plugin version.
     *
     * @return array<string, mixed>
     */
    public static function test_schema_version(): array
    {
        $installed = get_option(Migrator::OPTION_NAME, '0');

        $result = [
            'label'       => __('Common Goals schema is up to date', 'common-goals'),
            'status'      => 'good',
            'badge'       => ['label' => __('Common Goals', 'common-goals'), 'color' => 'green'],
            'description' => sprintf('<p>%s (%s = %s)</p>', esc_html__('Installed schema matches plugin version.', 'common-goals'), esc_html($installed), esc_html(COMMON_GOALS_VERSION)),
            'test'        => 'common_goals_schema',
        ];

        if (version_compare($installed, COMMON_GOALS_VERSION, '<')) {
            $result['status']      = 'warning';
            $result['label']       = __('Common Goals schema is outdated', 'common-goals');
            $result['badge']['color'] = 'orange';
            $result['description'] = sprintf('<p>%s (%s < %s)</p>', esc_html__('The database schema is behind the plugin version. A migration may be pending.', 'common-goals'), esc_html($installed), esc_html(COMMON_GOALS_VERSION));
        }

        return $result;
    }

    /**
     * Adds Common Goals debug info to the Site Health Info screen.
     *
     * @param array<string, mixed> $info Existing debug info.
     * @return array<string, mixed>
     */
    public static function register_debug_info(array $info): array
    {
        global $wpdb;

        $info['common-goals'] = [
            'label'  => __('Common Goals', 'common-goals'),
            'fields' => [
                'version'      => [
                    'label' => __('Plugin version', 'common-goals'),
                    'value' => COMMON_GOALS_VERSION,
                ],
                'schema'       => [
                    'label' => __('Schema version', 'common-goals'),
                    'value' => get_option(Migrator::OPTION_NAME, __('Not set', 'common-goals')),
                ],
                'contributions' => [
                    'label' => __('Total contributions', 'common-goals'),
                    'value' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Database::contributions_table()),
                ],
                'communities' => [
                    'label' => __('Total communities', 'common-goals'),
                    'value' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Database::communities_table()),
                ],
                'guides'       => [
                    'label' => __('Published guides', 'common-goals'),
                    'value' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Database::guides_table() . " WHERE status = 'published'"),
                ],
                'retention'    => [
                    'label' => __('Event retention (days)', 'common-goals'),
                    'value' => self::get_retention_days(),
                ],
            ],
        ];

        return $info;
    }

    /**
     * Returns the configured event retention period in days.
     */
    public static function get_retention_days(): int
    {
        return (int) get_option(self::RETENTION_OPTION, self::DEFAULT_RETENTION);
    }

    /**
     * Registers the daily cron interval.
     *
     * @param array<string, mixed> $schedules Existing schedules.
     * @return array<string, mixed>
     */
    public static function register_cron_interval(array $schedules): array
    {
        $schedules[self::CRON_INTERVAL_NAME] = [
            'interval' => DAY_IN_SECONDS,
            'display'  => __('Common Goals Daily', 'common-goals'),
        ];

        return $schedules;
    }

    /**
     * Schedules the cleanup cron event if not already scheduled.
     */
    public static function schedule_cron(): void
    {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_INTERVAL_NAME, self::CRON_HOOK);
        }
    }

    /**
     * Removes the scheduled cron event.
     */
    public static function unschedule_cron(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);

        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Deletes events older than the retention period.
     */
    public static function cleanup_old_events(): void
    {
        global $wpdb;

        $days     = self::get_retention_days();
        $cutoff   = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        $wpdb->query(
            $wpdb->prepare('DELETE FROM ' . Database::events_table() . ' WHERE created_at < %s', $cutoff)
        );
    }
}
