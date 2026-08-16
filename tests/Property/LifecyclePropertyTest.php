<?php
/**
 * Property-based tests for Migrator ordering and hook-registration idempotency.
 *
 * Covers spec cases PB-MIG-001 and PB-HOOK-001.
 *
 * @package CommonGoals\Tests\Property
 */

namespace CommonGoals\Tests\Property;

use Brain\Monkey\Functions;
use CommonGoals\Migrator;
use CommonGoals\Plugin;
use CommonGoals\Tests\Property\Support\PropertyRng;
use CommonGoals\Tests\Property\Support\PropertyTestCase;

final class LifecyclePropertyTest extends PropertyTestCase
{
    public function test_pb_mig_001_callbacks_run_only_for_versions_after_installed(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            $candidates = ['0', '0.1.0', '0.2.0', '0.9.0', COMMON_GOALS_VERSION, '99.0.0'];
            $installed = $candidates[$rng->between(0, count($candidates) - 1)];

            $dbdelta = 0;
            $options = [Migrator::OPTION_NAME => $installed];
            Functions\when('dbDelta')->alias(static function () use (&$dbdelta) {
                $dbdelta++;
                return [];
            });
            Functions\when('get_option')->alias(static fn($name, $default = false) => array_key_exists($name, $options) ? $options[$name] : $default);
            Functions\when('update_option')->alias(static function ($name, $value) use (&$options) {
                $options[$name] = $value;
            });
            Functions\when('get_bloginfo')->justReturn('Test');

            Migrator::run();

            $expect_deltas = 0;
            if (version_compare($installed, COMMON_GOALS_VERSION, '>=')) {
                $expect_deltas = 0;
            } else {
                if (version_compare($installed, '0.1.0', '<')) {
                    $expect_deltas += 12; // 0.1.0 base tables (incl. ai_runs)
                }
                if (version_compare($installed, '0.9.0', '<')) {
                    $expect_deltas += 2; // 0.9.0
                }
                if (version_compare($installed, '1.2.0', '<')) {
                    $expect_deltas += 1; // votes table
                }
                if (version_compare($installed, '1.7.0', '<')) {
                    $expect_deltas += 1; // bookmarks table
                }
                if (version_compare($installed, '1.8.0', '<')) {
                    $expect_deltas += 1; // reports table
                }
                if (version_compare($installed, '1.9.0', '<')) {
                    $expect_deltas += 1; // notifications table
                }
                if (version_compare($installed, '2.0.0', '<')) {
                    $expect_deltas += 1; // ai_runs table
                }
            }
            $this->assertSame($expect_deltas, $dbdelta, "Installed={$installed} produced unexpected delta count");

            if (version_compare($installed, COMMON_GOALS_VERSION, '<')) {
                $this->assertSame(COMMON_GOALS_VERSION, $options[Migrator::OPTION_NAME], 'Option must advance after successful run');
            }
        }, 100, 'PB-MIG-001');
    }

    public function test_pb_hook_001_repeated_registration_keeps_hooks_stable(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            $plugin = new Plugin();
            $plugin->register_hooks();
            $after_first = has_action('admin_post_common_goals_create_goal');
            $first_rest = has_action('rest_api_init');

            // Register again to simulate a second load.
            $plugin->register_hooks();

            $this->assertNotFalse($after_first);
            $this->assertNotFalse($first_rest);
            $this->assertNotFalse(has_action('admin_post_common_goals_create_goal'));
            $this->assertNotFalse(has_action('rest_api_init'));
        }, 30, 'PB-HOOK-001');
    }
}
