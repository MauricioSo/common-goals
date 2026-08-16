<?php
/**
 * Tests for Phase 3: guide router, workflow statuses, guide SEO.
 *
 * @package CommonGoals
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use CommonGoals\Domain;
use CommonGoals\Frontend\GuideRouter;

class Phase3Test extends TestCase
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

    public function test_guide_statuses_include_review(): void
    {
        $this->assertContains('draft', Domain::GUIDE_STATUSES);
        $this->assertContains('review', Domain::GUIDE_STATUSES);
        $this->assertContains('published', Domain::GUIDE_STATUSES);
        $this->assertContains('hidden', Domain::GUIDE_STATUSES);
    }

    public function test_guide_router_has_query_var_constant(): void
    {
        $this->assertSame('cg_guide_slug', GuideRouter::QUERY_VAR);
    }

    public function test_guide_router_has_rewrite_tag(): void
    {
        $this->assertSame('guias', GuideRouter::REWRITE_TAG);
    }

    public function test_guide_url_uses_home_url_and_rewrite_tag(): void
    {
        Functions\when('home_url')->returnArg();

        $url = GuideRouter::guide_url('my-guide');

        $this->assertSame('/guias/my-guide/', $url);
    }

    public function test_guide_url_with_empty_slug(): void
    {
        Functions\when('home_url')->returnArg();

        $url = GuideRouter::guide_url('');

        $this->assertSame('/guias//', $url);
    }

    public function test_guide_router_register_query_vars_adds_slug(): void
    {
        $router = new GuideRouter();
        $vars   = $router->register_query_vars(['existing_var']);

        $this->assertContains('cg_guide_slug', $vars);
        $this->assertContains('existing_var', $vars);
    }

    public function test_guide_router_register_query_vars_preserves_multiple(): void
    {
        $router = new GuideRouter();
        $vars   = $router->register_query_vars(['foo', 'bar']);

        $this->assertCount(5, $vars);
    }

    public function test_register_query_vars_returns_array(): void
    {
        $router = new GuideRouter();
        $vars   = $router->register_query_vars([]);

        $this->assertIsArray($vars);
    }
}
