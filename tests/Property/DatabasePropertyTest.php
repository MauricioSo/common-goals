<?php
/**
 * Property-based tests for Database table-name helpers.
 *
 * Covers spec case PB-DB-001.
 *
 * @package CommonGoals\Tests\Property
 */

namespace CommonGoals\Tests\Property;

use CommonGoals\Database;
use CommonGoals\Tests\Property\Support\PropertyRng;
use CommonGoals\Tests\Property\Support\PropertyTestCase;

final class DatabasePropertyTest extends PropertyTestCase
{
    public function test_pb_db_001_table_names_are_prefix_plus_fixed_suffix_and_distinct(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            $prefix = $rng->string($rng->between(1, 8), 'abcdefghijklmnopqrstuvwxyz0123456789_');
            $this->wpdb->prefix = $prefix;

            $names = [
                Database::communities_table(),
                Database::community_members_table(),
                Database::goals_table(),
                Database::contributions_table(),
                Database::responses_table(),
                Database::guides_table(),
                Database::events_table(),
            ];

            $expected = [
                $prefix . 'cg_communities',
                $prefix . 'cg_community_members',
                $prefix . 'cg_goals',
                $prefix . 'cg_contributions',
                $prefix . 'cg_responses',
                $prefix . 'cg_guides',
                $prefix . 'cg_events',
            ];
            $this->assertSame($expected, $names);
            $this->assertSame(count($expected), count(array_unique($names)), 'All table names must be distinct');
            $this->assertSame(0, $this->wpdb->count_method('get_var'), 'Helpers must not query the database');
        }, 200, 'PB-DB-001');
    }
}
