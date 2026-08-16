<?php

namespace CommonGoals\Tests\Integration;

use CommonGoals\Database;
use CommonGoals\Migrator;
use CommonGoals\Tests\Integration\Support\IntegrationTestCase;

final class CompatibilityTest extends IntegrationTestCase
{
    public function test_comp_001_plugin_version_constant(): void
    {
        $this->assertTrue(defined('COMMON_GOALS_VERSION'));
        $version = COMMON_GOALS_VERSION;
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
    }

    public function test_comp_002_php_version_meets_minimum(): void
    {
        $php_version = PHP_VERSION;
        $this->assertTrue(version_compare($php_version, '8.1.0', '>='));
    }

    public function test_comp_003_wp_version_meets_minimum(): void
    {
        global $wp_version;
        $this->assertTrue(version_compare($wp_version, '6.5', '>='));
    }

    public function test_comp_004_mysql_version_meets_minimum(): void
    {
        global $wpdb;
        $mysql_version = $wpdb->db_version();
        $this->assertTrue(version_compare($mysql_version, '5.7', '>='));
    }

    public function test_comp_005_all_tables_exist(): void
    {
        global $wpdb;

        $tables = [
            Database::communities_table(),
            Database::community_members_table(),
            Database::goals_table(),
            Database::contributions_table(),
            Database::responses_table(),
            Database::guides_table(),
            Database::events_table(),
        ];

        foreach ($tables as $table) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            $this->assertSame($table, $exists, "Table {$table} should exist");
        }
    }

    public function test_comp_006_migration_version_stored(): void
    {
        \CommonGoals\Migrator::run();
        $version = get_option(\CommonGoals\Migrator::OPTION_NAME, '0');
        $this->assertNotEmpty($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
    }

    public function test_comp_007_migrator_idempotent(): void
    {
        Migrator::run();
        $before = get_option(Migrator::OPTION_NAME);

        Migrator::run();
        $after = get_option(Migrator::OPTION_NAME);

        $this->assertSame($before, $after);
    }

    public function test_comp_008_wpdb_is_mysqli(): void
    {
        global $wpdb;
        $this->assertInstanceOf(\wpdb::class, $wpdb);
        $this->assertNotNull($wpdb->dbh);
    }

    public function test_comp_009_utf8mb4_charset(): void
    {
        global $wpdb;
        $charset = $wpdb->charset;
        $this->assertStringContainsString('utf8', $charset);
    }

    public function test_comp_010_rest_namespace_exists(): void
    {
        $server = rest_get_server();
        $namespaces = $server->get_namespaces();

        $this->assertContains('common-goals/v1', $namespaces);
    }

    public function test_comp_011_rest_routes_count(): void
    {
        $server = rest_get_server();
        $routes = $server->get_routes('common-goals/v1');

        $this->assertGreaterThanOrEqual(5, count($routes));
    }
}
