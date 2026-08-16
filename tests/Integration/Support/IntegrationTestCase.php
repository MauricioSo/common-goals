<?php
/**
 * Base integration test case for Common Goals.
 *
 * Loads against a real WordPress + MySQL stack (via the WP test bootstrap) but
 * does NOT depend on WP_UnitTestCase, which is tightly coupled to the PHPUnit
 * version shipped with WordPress core. State is reset between tests by
 * truncating the plugin tables and clearing relevant options/transients.
 *
 * @package CommonGoals\Tests\Integration\Support
 */

namespace CommonGoals\Tests\Integration\Support;

use CommonGoals\Database;
use CommonGoals\Migrator;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    private $cg_die_handler;

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->suppress_errors(true);
        $this->reset_plugin_state();
        wp_set_current_user(0);
        $this->install_wp_die_exception();
    }

    protected function tearDown(): void
    {
        $this->remove_wp_die_exception();
        $this->reset_plugin_state();
        parent::tearDown();
    }

    protected function reset_plugin_state(): void
    {
        global $wpdb;
        $this->ensure_plugin_schema();

        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->plugin_tables() as $table) {
            $wpdb->query("TRUNCATE TABLE {$table}");
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');

        foreach ([
            'common_goals_database_version',
            'common_goals_allow_guest_posting',
            'common_goals_event_retention_days',
            'common_goals_cleanup_on_uninstall',
            'common_goals_rate_limit_max',
            'common_goals_honeypot_enabled',
        ] as $option) {
            delete_option($option);
        }
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cg_rate_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_cg_rate_%'");
        wp_cache_flush();
    }

    private function ensure_plugin_schema(): void
    {
        global $wpdb;
        $communities = Database::communities_table();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $communities));
        if ($exists === $communities) {
            return;
        }
        foreach ([Database::responses_table(), Database::contributions_table(), Database::guides_table(), Database::events_table(), Database::goals_table(), Database::community_members_table(), Database::communities_table()] as $t) {
            $wpdb->query("DROP TABLE IF EXISTS {$t}");
        }
        $wpdb->suppress_errors(true);
        Database::create_tables();
        $wpdb->suppress_errors(false);
        update_option(Migrator::OPTION_NAME, COMMON_GOALS_VERSION);
    }

    private function plugin_tables(): array
    {
        return [
            Database::communities_table(),
            Database::community_members_table(),
            Database::goals_table(),
            Database::contributions_table(),
            Database::responses_table(),
            Database::guides_table(),
            Database::events_table(),
        ];
    }

    protected function act_as_admin(): void
    {
        $id = wp_insert_user([
            'user_login' => 'cgadmin_' . uniqid(),
            'user_email' => 'cgadmin_' . uniqid() . '@cg-test.test',
            'user_pass' => 'x',
            'role' => 'administrator',
        ]);
        \CommonGoals\Capabilities::register();
        wp_set_current_user($id);
    }

    protected function act_as_subscriber(): void
    {
        $id = wp_insert_user([
            'user_login' => 'cgsub_' . uniqid(),
            'user_email' => 'cgsub_' . uniqid() . '@cg-test.test',
            'user_pass' => 'x',
            'role' => 'subscriber',
        ]);
        wp_set_current_user($id);
    }

    protected function create_community(array $overrides = []): object
    {
        global $wpdb;
        $now = current_time('mysql');
        $defaults = [
            'name' => 'Test Community',
            'slug' => 'test-community',
            'description' => '',
            'status' => 'active',
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $row = array_merge($defaults, $overrides);
        $wpdb->insert(Database::communities_table(), $row);
        $row['id'] = (int) $wpdb->insert_id;
        return (object) $row;
    }

    protected function create_goal(int $community_id, array $overrides = []): object
    {
        global $wpdb;
        $now = current_time('mysql');
        $defaults = [
            'community_id' => $community_id,
            'title' => 'Test Goal',
            'description' => 'desc',
            'beneficiary' => 'users',
            'allowed_contribution_types' => '',
            'alignment_rules' => '',
            'status' => 'active',
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $row = array_merge($defaults, $overrides);
        $wpdb->insert(Database::goals_table(), $row);
        $row['id'] = (int) $wpdb->insert_id;
        return (object) $row;
    }

    protected function with_nonce(string $action): void
    {
        $_REQUEST['_wpnonce'] = wp_create_nonce($action);
        $_POST['_wpnonce'] = $_REQUEST['_wpnonce'];
    }

    protected function capture_redirect(callable $handler): ?string
    {
        $redirected = null;
        $cb = static function ($url) use (&$redirected) {
            $redirected = $url;
            throw new \RuntimeException('CG_REDIRECT');
        };
        add_filter('wp_redirect', $cb, 1);
        try {
            $handler();
        } catch (\Throwable $e) {
            if ($e->getMessage() !== 'CG_REDIRECT') {
                throw $e;
            }
        } finally {
            remove_filter('wp_redirect', $cb, 1);
        }
        return $redirected;
    }

    protected function expectWpDie(callable $handler): void
    {
        try {
            $handler();
            $this->fail('Expected WPDieException was not thrown');
        } catch (\WPDieException $e) {
            $this->addToAssertionCount(1);
        }
    }

    private function install_wp_die_exception(): void
    {
        $this->cg_die_handler = static function () {
            return static function (string $message, string $title = '', array $args = []): void {
                throw new \WPDieException($message, (int) ($args['code'] ?? 500));
            };
        };
        add_filter('wp_die_handler', $this->cg_die_handler, 999999);
    }

    private function remove_wp_die_exception(): void
    {
        if ($this->cg_die_handler !== null) {
            remove_filter('wp_die_handler', $this->cg_die_handler, 999999);
        }
    }
}
