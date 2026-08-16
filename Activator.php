<?php
/**
 * Plugin activation tasks.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Runs schema migrations and capability registration during activation.
 */
final class Activator
{
    /**
     * Runs activation tasks.
     */
    public static function activate(): void
    {
        Migrator::run();
        Capabilities::register();

        $router = new Frontend\GuideRouter();
        $router->register_rewrite_rules();

        flush_rewrite_rules();
        SiteHealth::schedule_cron();
    }
}
