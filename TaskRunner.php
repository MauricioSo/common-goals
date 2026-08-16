<?php
/**
 * Async task runner with optional ActionScheduler integration.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Schedules background tasks using ActionScheduler when available and
 * falls back to direct execution when it is not.
 *
 * This keeps export generation, event cleanup and notification batching
 * off the request path on sites that have ActionScheduler (WooCommerce,
 * etc.) while remaining functional on simpler installations.
 */
final class TaskRunner
{
    public const GROUP = 'common-goals';

    /**
     * Schedules a single async task.
     *
     * @param string                $hook  The action hook to trigger.
     * @param array<int, mixed>    $args  Arguments for the hook.
     */
    public static function schedule(string $hook, array $args = []): void
    {
        if (self::is_available()) {
            if (! as_has_scheduled_action($hook, $args, self::GROUP)) {
                as_enqueue_async_action($hook, $args, self::GROUP);
            }
        } else {
            do_action($hook, ...$args);
        }
    }

    /**
     * Schedules a recurring task.
     *
     * @param string                $hook     The action hook to trigger.
     * @param int                   $interval Interval in seconds.
     * @param array<int, mixed>    $args     Arguments for the hook.
     */
    public static function schedule_recurring(string $hook, int $interval, array $args = []): void
    {
        if (self::is_available()) {
            if (! as_next_scheduled_action($hook, $args, self::GROUP)) {
                as_schedule_recurring_action(time(), $interval, $hook, $args, self::GROUP);
            }
        }
    }

    /**
     * Unschedules a task.
     *
     * @param string                $hook The action hook.
     * @param array<int, mixed>    $args Arguments for the hook.
     */
    public static function unschedule(string $hook, array $args = []): void
    {
        if (self::is_available()) {
            as_unschedule_action($hook, $args, self::GROUP);
        }
    }

    /**
     * Returns true if ActionScheduler is loaded.
     */
    public static function is_available(): bool
    {
        return function_exists('as_enqueue_async_action');
    }
}
