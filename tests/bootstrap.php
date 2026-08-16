<?php
/**
 * PHPUnit bootstrap file.
 *
 * Loads Brain Monkey and Yoast Polyfills for testing WordPress-bound code
 * without a live WordPress instance.
 *
 * @package CommonGoals
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

define('ABSPATH', __DIR__ . '/fixtures/wordpress/');

$wp_admin_includes = __DIR__ . '/fixtures/wordpress/wp-admin/includes/';

if (! is_dir($wp_admin_includes)) {
    mkdir($wp_admin_includes, 0777, true);
}

if (! file_exists($wp_admin_includes . 'upgrade.php')) {
    file_put_contents($wp_admin_includes . 'upgrade.php', '<?php if (! function_exists("dbDelta")) { function dbDelta($sql = []) { return []; } }');
}

define('COMMON_GOALS_VERSION', '1.0.1');
define('COMMON_GOALS_PLUGIN_DIR', __DIR__ . '/../common-goals/');
define('COMMON_GOALS_PLUGIN_URL', 'https://example.com/wp-content/plugins/common-goals/');

define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);

define('ARRAY_A', 'ARRAY_A');
define('OBJECT', 'OBJECT');

if (! class_exists('WP_Sitemaps_Provider')) {
    require_once __DIR__ . '/stubs/class-wp-sitemaps-provider.php';
}

require_once __DIR__ . '/stubs/class-wp-rest-request.php';
require_once __DIR__ . '/stubs/class-wp-rest-response.php';
