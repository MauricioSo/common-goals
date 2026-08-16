<?php
/**
 * Central event logger for audit, analytics, and future Cloud sync.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Writes meaningful community events into the cg_events table.
 *
 * Every creation, update, and moderation action should be recorded here so the
 * plugin can later produce analytics, exports, and Cloud synchronization without
 * reverse-engineering the domain tables.
 */
final class EventLogger
{
    /**
     * Stores a single event row.
     *
     * @param string               $object_type Entity kind (goal, contribution, response, guide).
     * @param int                  $object_id   Entity identifier.
     * @param string               $event_type  Dotted event name (for example goal.created).
     * @param array<string, mixed> $event_data  Optional structured context for the event.
     * @return bool True on success, false on failure.
     */
    public static function log(string $object_type, int $object_id, string $event_type, array $event_data = []): bool
    {
        global $wpdb;

        /* Dependencies. */
        $events_table = Database::events_table();

        /* Processing. */
        $result = $wpdb->insert(
            $events_table,
            [
                'object_type' => $object_type,
                'object_id'   => $object_id,
                'event_type'  => $event_type,
                'event_data'  => $event_data === [] ? null : wp_json_encode($event_data),
                'created_by'  => get_current_user_id(),
                'created_at'  => current_time('mysql'),
            ],
            ['%s', '%d', '%s', '%s', '%d', '%s']
        );

        return $result !== false;
    }
}
