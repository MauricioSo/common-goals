<?php
/**
 * Integration test bootstrap for Common Goals.
 *
 * Loads the WordPress test framework against the existing Herd WordPress core
 * and a disposable test database, then activates the plugin manually.
 *
 * @package CommonGoals
 */

require_once __DIR__ . '/../../vendor/autoload.php';

define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', __DIR__ . '/../../vendor/yoast/phpunit-polyfills');
define('WP_TESTS_CONFIG_FILE_PATH', getenv('CG_WP_TESTS_CONFIG') ?: 'C:/Users/usuario/AppData/Local/Temp/opencode/wp-tests-lib/wp-tests-config.php');

require_once 'C:/Users/usuario/AppData/Local/Temp/opencode/wp-tests-lib/develop/tests/phpunit/includes/bootstrap.php';

$plugin_file = __DIR__ . '/../../common-goals/common-goals.php';
require_once $plugin_file;

if (function_exists('common_goals_start_plugin')) {
    global $wpdb;
    $wpdb->suppress_errors(true);
    common_goals_start_plugin();
    $wpdb->suppress_errors(false);
}

if (!function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
add_filter('pre_option_active_plugins', static function ($plugins) use ($plugin_file) {
    $basename = plugin_basename($plugin_file);
    if (!is_array($plugins)) {
        $plugins = [];
    }
    if (!in_array($basename, $plugins, true)) {
        $plugins[] = $basename;
    }
    return $plugins;
});
