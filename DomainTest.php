<?php
/**
 * Tests for the Domain class: statuses, types, limits and validation helpers.
 *
 * @package CommonGoals
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use CommonGoals\Domain;

class DomainTest extends TestCase
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

    public function test_contribution_types_contain_the_four_mvp_types(): void
    {
        $this->assertSame(['question', 'problem', 'experience', 'resource'], Domain::CONTRIBUTION_TYPES);
    }

    public function test_public_statuses_exclude_hidden_pending_and_spam(): void
    {
        $this->assertSame(['open', 'in_progress', 'resolved'], Domain::PUBLIC_STATUSES);
        $this->assertNotContains('hidden', Domain::PUBLIC_STATUSES);
        $this->assertNotContains('pending', Domain::PUBLIC_STATUSES);
        $this->assertNotContains('spam', Domain::PUBLIC_STATUSES);
    }

    public function test_contribution_statuses_include_pending_and_spam_for_moderation(): void
    {
        $this->assertContains('pending', Domain::CONTRIBUTION_STATUSES);
        $this->assertContains('spam', Domain::CONTRIBUTION_STATUSES);
        $this->assertContains('hidden', Domain::CONTRIBUTION_STATUSES);
        $this->assertContains('open', Domain::CONTRIBUTION_STATUSES);
        $this->assertContains('resolved', Domain::CONTRIBUTION_STATUSES);
    }

    public function test_field_length_limits_are_positive(): void
    {
        $this->assertGreaterThan(0, Domain::MAX_TITLE_LENGTH);
        $this->assertGreaterThan(0, Domain::MAX_TOPIC_LENGTH);
        $this->assertGreaterThan(0, Domain::MAX_BODY_LENGTH);
        $this->assertGreaterThan(0, Domain::MAX_RESPONSE_LENGTH);
    }

    public function test_allowed_types_for_goal_returns_decoded_types_when_valid(): void
    {
        $goal = (object) ['allowed_contribution_types' => json_encode(['question', 'problem'])];

        $this->assertSame(['question', 'problem'], Domain::allowed_types_for_goal($goal));
    }

    public function test_allowed_types_for_goal_falls_back_to_defaults_when_empty(): void
    {
        $goal = (object) ['allowed_contribution_types' => ''];

        $this->assertSame(Domain::CONTRIBUTION_TYPES, Domain::allowed_types_for_goal($goal));
    }

    public function test_allowed_types_for_goal_filters_invalid_values(): void
    {
        $goal = (object) ['allowed_contribution_types' => json_encode(['question', 'bogus', 'invalid'])];

        $this->assertSame(['question'], Domain::allowed_types_for_goal($goal));
    }

    public function test_allowed_types_for_goal_returns_defaults_when_goal_is_null(): void
    {
        $this->assertSame(Domain::CONTRIBUTION_TYPES, Domain::allowed_types_for_goal(null));
    }

    public function test_allowed_types_for_goal_returns_defaults_when_json_is_malformed(): void
    {
        $goal = (object) ['allowed_contribution_types' => 'not-json{'];

        $this->assertSame(Domain::CONTRIBUTION_TYPES, Domain::allowed_types_for_goal($goal));
    }

    public function test_allowed_types_for_goal_returns_defaults_when_all_values_filtered(): void
    {
        $goal = (object) ['allowed_contribution_types' => json_encode(['bogus', 'invalid'])];

        $this->assertSame(Domain::CONTRIBUTION_TYPES, Domain::allowed_types_for_goal($goal));
    }

    public function test_get_active_goal_returns_null_for_zero_id(): void
    {
        $this->assertNull(Domain::get_active_goal(0));
    }

    public function test_get_active_goal_returns_null_for_negative_id(): void
    {
        $this->assertNull(Domain::get_active_goal(-5));
    }

    public function test_get_visible_contribution_returns_null_for_zero_id(): void
    {
        $this->assertNull(Domain::get_visible_contribution(0));
    }

    public function test_honeypot_triggered_returns_false_when_field_is_empty(): void
    {
        Functions\when('get_option')->justReturn(1);
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        $_POST['cg_website'] = '';

        $this->assertFalse(Domain::honeypot_triggered());
    }

    public function test_honeypot_triggered_returns_true_when_field_is_filled(): void
    {
        Functions\when('get_option')->justReturn(1);
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        $_POST['cg_website'] = 'spam link';

        $this->assertTrue(Domain::honeypot_triggered());
    }

    public function test_rate_limit_max_falls_back_to_constant_when_option_absent(): void
    {
        Functions\when('get_option')->justReturn(null);
        Functions\when('absint')->alias(static fn($v) => (int) abs((int) $v));

        $this->assertSame(Domain::RATE_LIMIT_MAX, Domain::rate_limit_max());
    }

    public function test_rate_limit_max_honors_configured_option(): void
    {
        Functions\when('get_option')->justReturn(10);
        Functions\when('absint')->alias(static fn($v) => (int) abs((int) $v));

        $this->assertSame(10, Domain::rate_limit_max());
    }

    public function test_rate_limit_max_ignores_non_positive_option(): void
    {
        Functions\when('get_option')->justReturn(0);
        Functions\when('absint')->alias(static fn($v) => (int) abs((int) $v));

        $this->assertSame(Domain::RATE_LIMIT_MAX, Domain::rate_limit_max());
    }

    public function test_is_spam_flags_content_with_excessive_links(): void
    {
        Functions\when('apply_filters')->returnArg(2);

        $content = 'see http://a.com http://b.com http://c.com http://d.com http://e.com';

        $this->assertTrue(Domain::is_spam($content, 'contribution'));
    }

    public function test_is_spam_does_not_flag_normal_content(): void
    {
        Functions\when('apply_filters')->returnArg(2);

        $this->assertFalse(Domain::is_spam('A normal question about onboarding.', 'contribution'));
    }

    public function test_access_helpers_return_false_for_anonymous_user(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(0);

        $this->assertFalse(Domain::current_user_can_access_goal_management());
        $this->assertFalse(Domain::current_user_can_access_moderation());
        $this->assertFalse(Domain::current_user_can_access_guides());
        $this->assertFalse(Domain::current_user_can_access_events());
        $this->assertFalse(Domain::current_user_can_access_communities());
        $this->assertFalse(Domain::current_user_can_access_any_admin_area());
    }

    public function test_access_helpers_return_true_when_global_cap_present(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(0);

        $this->assertTrue(Domain::current_user_can_access_moderation());
        $this->assertTrue(Domain::current_user_can_access_any_admin_area());
    }
}
