<?php
/**
 * Smoke test validating the WordPress integration harness.
 *
 * @package CommonGoals\Tests\Integration
 */

namespace CommonGoals\Tests\Integration;

use CommonGoals\Database;
use CommonGoals\Domain;
use CommonGoals\Tests\Integration\Support\IntegrationTestCase;

/**
 * Verifies that WordPress, the test database and the plugin are all loaded.
 */
class SmokeTest extends IntegrationTestCase
{
    public function test_wordpress_is_loaded_with_test_database(): void
    {
        $this->assertTrue(function_exists('wp_insert_user'));
        $this->assertSame('cg_wptests', DB_NAME, 'Integration tests must NOT run against the live site database');
    }

    public function test_plugin_classes_are_available(): void
    {
        $this->assertTrue(class_exists(Domain::class));
        $this->assertTrue(class_exists(Database::class));
    }

    public function test_plugin_tables_exist(): void
    {
        global $wpdb;
        $found = $wpdb->get_col("SHOW TABLES LIKE '" . $wpdb->prefix . "cg_%'");
        sort($found);
        $this->assertContains($wpdb->prefix . 'cg_goals', $found);
        $this->assertContains($wpdb->prefix . 'cg_communities', $found);
        $this->assertContains($wpdb->prefix . 'cg_events', $found);
        $this->assertContains($wpdb->prefix . 'cg_votes', $found);
        $this->assertContains($wpdb->prefix . 'cg_bookmarks', $found);
        $this->assertContains($wpdb->prefix . 'cg_reports', $found);
        $this->assertContains($wpdb->prefix . 'cg_notifications', $found);
        $this->assertCount(11, $found, 'Exactly eleven Common Goals tables must exist');
    }

    public function test_factory_helpers_persist_rows(): void
    {
        $c = $this->create_community(['name' => 'Alpha', 'slug' => 'alpha']);
        $g = $this->create_goal((int) $c->id, ['title' => 'Goal A']);
        $this->assertGreaterThan(0, $c->id);
        $this->assertGreaterThan(0, $g->id);
        $this->assertSame('Alpha', Domain::get_community((int) $c->id)->name);
    }
}
