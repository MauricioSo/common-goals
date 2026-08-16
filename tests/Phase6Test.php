<?php
/**
 * Tests for Phase 6: settings, template loader, sitemap, anti-spam hooks.
 *
 * @package CommonGoals
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use CommonGoals\Domain;
use CommonGoals\TemplateLoader;
use CommonGoals\GuideSitemap;

class Phase6Test extends TestCase
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

    public function test_template_loader_theme_dir_constant(): void
    {
        $this->assertSame('common-goals', TemplateLoader::THEME_DIR);
    }

    public function test_is_spam_returns_false_by_default(): void
    {
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('get_option')->justReturn('');

        $this->assertFalse(Domain::is_spam('hello world', 'contribution'));
    }

    public function test_is_spam_filter_can_flag_content(): void
    {
        Functions\stubs([
            'apply_filters' => true,
            'get_option'    => '',
        ]);

        $this->assertTrue(Domain::is_spam('buy viagra now', 'contribution'));
    }

    public function test_honeypot_respects_disabled_option(): void
    {
        Functions\when('get_option')->justReturn(0);

        $this->assertFalse(Domain::honeypot_triggered());
    }

    public function test_honeypot_triggered_when_enabled_and_filled(): void
    {
        Functions\when('get_option')->justReturn(1);
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        $_POST['cg_website'] = 'spam';

        $this->assertTrue(Domain::honeypot_triggered());

        unset($_POST['cg_website']);
    }

    public function test_honeypot_not_triggered_when_enabled_and_empty(): void
    {
        Functions\when('get_option')->justReturn(1);
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        $_POST['cg_website'] = '';

        $this->assertFalse(Domain::honeypot_triggered());

        unset($_POST['cg_website']);
    }

    public function test_guide_sitemap_register_provider_adds_entry(): void
    {
        $providers = GuideSitemap::register_provider(['posts' => null]);

        $this->assertArrayHasKey('common_goals_guides', $providers);
        $this->assertArrayHasKey('posts', $providers);
    }

    public function test_rate_limit_uses_configured_max(): void
    {
        $this->assertGreaterThan(0, Domain::RATE_LIMIT_MAX);
    }

    public function test_rate_limit_window_is_positive(): void
    {
        $this->assertGreaterThan(0, Domain::RATE_LIMIT_WINDOW);
    }
}
