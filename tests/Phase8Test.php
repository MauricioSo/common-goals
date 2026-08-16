<?php
/**
 * Tests for Phase 8: REST API namespace, block registration, task runner.
 *
 * @package CommonGoals
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use CommonGoals\RestApi;
use CommonGoals\TaskRunner;
use CommonGoals\Blocks;

class Phase8Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_rest_namespace_is_versioned(): void
    {
        $this->assertSame('common-goals/v1', RestApi::NAMESPACE);
    }

    public function test_rest_register_routes_fires_on_rest_api_init(): void
    {
        $fired = [];

        Functions\when('add_action')->alias(function ($hook) use (&$fired) {
            $fired[] = $hook;
        });

        RestApi::register_hooks();

        $this->assertContains('rest_api_init', $fired);
    }

    public function test_task_runner_group_constant(): void
    {
        $this->assertSame('common-goals', TaskRunner::GROUP);
    }

    public function test_task_runner_is_available_returns_false_without_as(): void
    {
        $this->assertFalse(TaskRunner::is_available());
    }

    public function test_task_runner_schedule_falls_back_to_do_action(): void
    {
        Monkey\Actions\expectDone('cg_test_hook')->once();

        TaskRunner::schedule('cg_test_hook');

        $this->addToAssertionCount(1);
    }

    public function test_blocks_register_hooks_fires_on_init(): void
    {
        $fired = [];

        Functions\when('add_action')->alias(function ($hook) use (&$fired) {
            $fired[] = $hook;
        });

        Blocks::register_hooks();

        $this->assertContains('init', $fired);
    }

    public function test_allowed_types_filter_applies(): void
    {
        Functions\when('apply_filters')->returnArg(2);

        $goal = (object) ['allowed_contribution_types' => json_encode(['question'])];

        $result = \CommonGoals\Domain::allowed_types_for_goal($goal);

        $this->assertSame(['question'], $result);
    }
}
