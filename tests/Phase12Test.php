<?php
/**
 * Tests for Phase 12 community-scoped permissions.
 *
 * @package CommonGoals
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use CommonGoals\Domain;

class Phase12Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_community_scope_sql_rejects_empty_scope(): void
    {
        $this->assertSame('1 = 0', Domain::community_scope_sql('goals.community_id', []));
    }

    public function test_community_scope_sql_builds_integer_allow_list(): void
    {
        $this->assertSame('goals.community_id IN (2,7)', Domain::community_scope_sql('goals.community_id', [2, 0, 7]));
    }

    public function test_global_manage_capability_can_manage_any_community(): void
    {
        Functions\when('current_user_can')->alias(static fn ($capability) => $capability === \CommonGoals\Capabilities::MANAGE);

        $this->assertTrue(Domain::current_user_can_manage_community(123));
    }

    public function test_community_admin_can_manage_assigned_community(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(44);

        $GLOBALS['wpdb'] = new class {
            public $prefix = 'wp_';

            public function prepare($query, ...$args)
            {
                return $query;
            }

            public function get_var($query)
            {
                return 'admin';
            }
        };

        $this->assertTrue(Domain::current_user_can_manage_community(9));
    }
}
