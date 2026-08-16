<?php
/**
 * Property-based tests for SQL visibility and community-isolation invariants.
 *
 * Covers spec cases PB-SQL-001 and PB-SQL-002.
 *
 * @package CommonGoals\Tests\Property
 */

namespace CommonGoals\Tests\Property;

use Brain\Monkey\Functions;
use CommonGoals\Domain;
use CommonGoals\Tests\Property\Support\PropertyRng;
use CommonGoals\Tests\Property\Support\PropertyTestCase;

final class SqlVisibilityPropertyTest extends PropertyTestCase
{
    public function test_pb_sql_001_visible_contribution_query_always_uses_positive_list(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            $this->wpdb->queue_get_row(null);
            $id = $rng->between(1, 99999);

            Domain::get_visible_contribution($id);

            $sqls = $this->wpdb->sql_strings();
            $has_public = false;
            foreach ($sqls as $sql) {
                if (stripos($sql, "'open'") !== false && stripos($sql, "'in_progress'") !== false && stripos($sql, "'resolved'") !== false) {
                    $has_public = true;
                }
                $this->assertStringNotContainsString("'pending'", $sql);
                $this->assertStringNotContainsString("'spam'", $sql);
                $this->assertStringNotContainsString("'hidden'", $sql);
            }
            $this->assertTrue($has_public, 'Query must always include the public positive list');
        }, 200, 'PB-SQL-001');
    }

    public function test_pb_sql_002_community_scope_does_not_conflate_ids_1_and_10(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            // The IN(...) allow-list must never match 10 when only 1 is allowed.
            $allowed = $rng->element([[1], [10], [1, 10], [1, 2], [10, 20]]);
            $sql = Domain::community_scope_sql('community_id', $allowed);

            $this->assertStringNotContainsString('1 = 0', $sql);
            $this->assertStringStartsWith('community_id IN (', $sql);

            $inner = substr($sql, strlen('community_id IN ('), -1);
            $numbers = array_map('intval', explode(',', $inner));

            foreach ($allowed as $a) {
                $this->assertContains($a, $numbers);
            }
            // If 10 is not in the allow-list, it must not appear in the IN list.
            if (!in_array(10, $allowed, true)) {
                $this->assertNotContains(10, $numbers);
            }
            if (!in_array(1, $allowed, true)) {
                $this->assertNotContains(1, $numbers);
            }
        }, 200, 'PB-SQL-002');
    }
}
