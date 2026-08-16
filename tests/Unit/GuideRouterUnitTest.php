<?php
/**
 * Unit tests for GuideRouter rewrite rules, query vars and URL builders.
 *
 * Covers spec case UT-ROUTER-001.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use CommonGoals\Frontend\GuideRouter;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class GuideRouterUnitTest extends UnitTestCase
{
    public function test_ut_router_001_register_rewrite_rules_adds_all_routes(): void
    {
        $rules = [];
        add_filter('cg_capture_rewrite', static function ($rule) use (&$rules) {
            $rules[] = $rule;
            return $rule;
        });

        // Brain Monkey add_rewrite_rule is stubbed; capture via a custom approach:
        // re-stub to record calls.
        \Brain\Monkey\Functions\when('add_rewrite_rule')->alias(static function ($regex, $query, $after) use (&$rules) {
            $rules[] = $regex . ' => ' . $query;
        });

        $router = new GuideRouter();
        $router->register_rewrite_rules();

        $this->assertCount(3, $rules);
        $this->assertStringContainsString('^guias/([^/]+)/?$', $rules[0]);
        $this->assertStringContainsString('cg_guide_slug', $rules[0]);
        $this->assertStringContainsString('^aportes/([0-9]+)/?$', $rules[1]);
        $this->assertStringContainsString('cg_contribution_id', $rules[1]);
        $this->assertStringContainsString('^autor/([0-9]+)/?$', $rules[2]);
        $this->assertStringContainsString('cg_user_id', $rules[2]);
    }

    public function test_ut_router_001_register_query_vars_adds_both_without_removing_existing(): void
    {
        $router = new GuideRouter();

        $result = $router->register_query_vars(['existing_var']);

        $this->assertContains('existing_var', $result);
        $this->assertContains(GuideRouter::QUERY_VAR, $result);
        $this->assertContains(GuideRouter::CONTRIBUTION_VAR, $result);
        $this->assertContains(GuideRouter::AUTHOR_VAR, $result);
        $this->assertCount(4, $result);
    }

    public function test_ut_router_001_guide_url_uses_home_url_with_rewrite_tag(): void
    {
        $url = GuideRouter::guide_url('my-slug');

        $this->assertSame('https://example.test/guias/my-slug/', $url);
    }

    public function test_ut_router_001_contribution_url_uses_home_url_with_id(): void
    {
        $this->assertSame('https://example.test/aportes/42/', GuideRouter::contribution_url(42));
    }

    public function test_ut_router_001_author_url_uses_home_url_with_id(): void
    {
        $this->assertSame('https://example.test/autor/7/', GuideRouter::author_url(7));
    }

    public function test_ut_router_001_rewrite_tags_constants_are_stable(): void
    {
        $this->assertSame('guias', GuideRouter::REWRITE_TAG);
        $this->assertSame('aportes', GuideRouter::CONTRIBUTION_TAG);
        $this->assertSame('autor', GuideRouter::AUTHOR_TAG);
    }
}
