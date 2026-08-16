<?php
/**
 * Tests for Phase 7: notifications hooks, contribution URL, edit/delete.
 *
 * @package CommonGoals
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use CommonGoals\Frontend\GuideRouter;
use CommonGoals\Notifications;

class Phase7Test extends TestCase
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

    public function test_contribution_var_constant(): void
    {
        $this->assertSame('cg_contribution_id', GuideRouter::CONTRIBUTION_VAR);
    }

    public function test_contribution_tag_constant(): void
    {
        $this->assertSame('aportes', GuideRouter::CONTRIBUTION_TAG);
    }

    public function test_contribution_url_uses_home_url(): void
    {
        Functions\when('home_url')->returnArg();

        $url = GuideRouter::contribution_url(42);

        $this->assertSame('/aportes/42/', $url);
    }

    public function test_register_query_vars_includes_contribution_var(): void
    {
        $router = new GuideRouter();
        $vars   = $router->register_query_vars(['existing']);

        $this->assertContains('cg_contribution_id', $vars);
        $this->assertContains('cg_guide_slug', $vars);
    }

    public function test_notifications_register_hooks_adds_three_actions(): void
    {
        $actions_fired = [];

        Functions\when('add_action')->alias(function ($hook, $callback) use (&$actions_fired) {
            $actions_fired[] = $hook;
        });

        Notifications::register_hooks();

        $this->assertContains('common_goals_contribution_created', $actions_fired);
        $this->assertContains('common_goals_response_created', $actions_fired);
        $this->assertContains('common_goals_contribution_status_changed', $actions_fired);
    }
}
