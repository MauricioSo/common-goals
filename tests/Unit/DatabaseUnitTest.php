<?php
/**
 * Unit tests for Database table helpers and schema creation.
 *
 * Covers spec cases UT-DB-001 and UT-DB-002.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use Brain\Monkey\Functions;
use CommonGoals\Database;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class DatabaseUnitTest extends UnitTestCase
{
    public function test_ut_db_001_table_helpers_use_wpdb_prefix(): void
    {
        $this->assertSame('wp_cg_communities', Database::communities_table());
        $this->assertSame('wp_cg_community_members', Database::community_members_table());
        $this->assertSame('wp_cg_goals', Database::goals_table());
        $this->assertSame('wp_cg_contributions', Database::contributions_table());
        $this->assertSame('wp_cg_responses', Database::responses_table());
        $this->assertSame('wp_cg_guides', Database::guides_table());
        $this->assertSame('wp_cg_events', Database::events_table());
        $this->assertSame('wp_cg_votes', Database::votes_table());
        $this->assertSame('wp_cg_bookmarks', Database::bookmarks_table());
        $this->assertSame('wp_cg_reports', Database::reports_table());
        $this->assertSame('wp_cg_notifications', Database::notifications_table());
    }

    public function test_ut_db_001_table_helpers_respect_custom_prefix(): void
    {
        $this->wpdb->prefix = 'tenant_7_';

        $this->assertSame('tenant_7_cg_goals', Database::goals_table());
        $this->assertSame('tenant_7_cg_events', Database::events_table());
        $this->assertSame('tenant_7_cg_community_members', Database::community_members_table());
        $this->assertSame('tenant_7_cg_votes', Database::votes_table());
        $this->assertSame('tenant_7_cg_notifications', Database::notifications_table());
    }

    public function test_ut_db_002_create_tables_emits_dbdelta_calls_with_expected_schema(): void
    {
        $captured = [];
        Functions\when('dbDelta')->alias(static function ($sql) use (&$captured) {
            $captured[] = $sql;
            return [];
        });

        Database::create_tables();

        $this->assertCount(12, $captured, 'create_tables must produce exactly twelve dbDelta calls');

        $this->assertDdlContains($captured[0], 'cg_communities', ['PRIMARY KEY', "UNIQUE KEY slug", 'KEY status']);
        $this->assertDdlContains($captured[1], 'cg_community_members', ['PRIMARY KEY', 'UNIQUE KEY community_user', 'KEY user_id']);
        $this->assertDdlContains($captured[2], 'cg_goals', ['PRIMARY KEY', 'KEY community_id', 'KEY status']);
        $this->assertDdlContains($captured[3], 'cg_contributions', ['PRIMARY KEY', 'KEY goal_id', 'KEY goal_status_created']);
        $this->assertDdlContains($captured[4], 'cg_responses', ['PRIMARY KEY', 'KEY contribution_status_created']);
        $this->assertDdlContains($captured[5], 'cg_guides', ['PRIMARY KEY', 'UNIQUE KEY slug', 'KEY status_updated']);
        $this->assertDdlContains($captured[6], 'cg_events', ['PRIMARY KEY', 'KEY object_lookup', 'KEY created_at_id']);
        $this->assertDdlContains($captured[7], 'cg_votes', ['PRIMARY KEY', 'UNIQUE KEY user_object', 'KEY object_lookup']);
        $this->assertDdlContains($captured[8], 'cg_bookmarks', ['PRIMARY KEY', 'UNIQUE KEY user_contribution', 'KEY user_id']);
        $this->assertDdlContains($captured[9], 'cg_reports', ['PRIMARY KEY', 'KEY object_lookup', 'KEY status']);
        $this->assertDdlContains($captured[10], 'cg_notifications', ['PRIMARY KEY', 'KEY user_read', 'KEY user_id']);
    }

    public function test_ut_db_002_create_tables_uses_charset_collate(): void
    {
        $captured = [];
        Functions\when('dbDelta')->alias(static function ($sql) use (&$captured) {
            $captured[] = $sql;
            return [];
        });

        Database::create_tables();

        foreach ($captured as $ddl) {
            $this->assertStringContainsString('DEFAULT CHARSET=utf8mb4', $ddl);
        }
    }

    public function test_ut_db_002_contributions_table_declares_default_pending_status(): void
    {
        $captured = [];
        Functions\when('dbDelta')->alias(static function ($sql) use (&$captured) {
            $captured[] = $sql;
            return [];
        });

        Database::create_tables();

        $contributions = $captured[3];
        $this->assertStringContainsString("status varchar(40) NOT NULL DEFAULT 'pending'", $contributions);
        $this->assertStringContainsString("user_id bigint(20) unsigned NOT NULL DEFAULT 0", $contributions);
    }

    /**
     * @param array<int, string> $needles
     */
    private function assertDdlContains(string $ddl, string $table, array $needles): void
    {
        $this->assertStringContainsString($table, $ddl);
        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $ddl, "DDL for {$table} missing: {$needle}");
        }
    }
}
