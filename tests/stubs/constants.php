<?php
/** @phpstan-stubs for Common Goals plugin constants */

define('COMMON_GOALS_VERSION', '0.2.0');
define('COMMON_GOALS_PLUGIN_FILE', __FILE__);
define('COMMON_GOALS_PLUGIN_DIR', __DIR__ . '/');
define('COMMON_GOALS_PLUGIN_URL', 'https://example.com/');

define('ARRAY_A', 'ARRAY_A');
define('OBJECT', 'OBJECT');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);

function as_has_scheduled_action($hook, $args = null, $group = '') { return false; }
function as_enqueue_async_action($hook, $args = [], $group = '') { return 0; }
function as_next_scheduled_action($hook, $args = null, $group = '') { return false; }
function as_schedule_recurring_action($timestamp, $interval, $hook, $args = [], $group = '') { return 0; }
function as_unschedule_action($hook, $args = [], $group = '') { return; }
