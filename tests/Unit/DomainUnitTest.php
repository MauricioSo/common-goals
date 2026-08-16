<?php
/**
 * Unit tests for Domain rules: types, scope, permissions, rate limit, spam,
 * honeypot, statuses and transitions.
 *
 * Covers spec cases UT-DOM-001 to UT-DOM-008, complementing the existing
 * DomainTest with boundary, isolation and filter-interaction angles.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use Brain\Monkey\Functions;
use CommonGoals\Capabilities;
use CommonGoals\Domain;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class DomainUnitTest extends UnitTestCase
{
    public function test_ut_dom_001_allowed_types_filter_receives_result_and_goal(): void
    {
        $goal = (object) ['allowed_contribution_types' => json_encode(['question', 'problem'])];
        $captured = null;
        Functions\when('apply_filters')->alias(static function ($tag, $value, ...$rest) use (&$captured, $goal) {
            if ($tag === 'common_goals_allowed_types') {
                $captured = ['value' => $value, 'goal' => $rest[0] ?? null];
            }
            return $value;
        });

        $result = Domain::allowed_types_for_goal($goal);

        $this->assertSame(['question', 'problem'], $result);
        $this->assertNotNull($captured);
        $this->assertSame(['question', 'problem'], $captured['value']);
        $this->assertSame($goal, $captured['goal']);
    }

    public function test_ut_dom_001_allowed_types_preserves_order_and_reindexes(): void
    {
        $goal = (object) ['allowed_contribution_types' => json_encode(['resource', 'question', 'resource', 'problem'])];
        Functions\when('apply_filters')->returnArg(2);

        // Domain filters invalid types and reindexes, but does not deduplicate.
        $this->assertSame(['resource', 'question', 'resource', 'problem'], Domain::allowed_types_for_goal($goal));
    }

    public function test_ut_dom_002_get_community_returns_null_for_non_positive_id(): void
    {
        $this->assertNull(Domain::get_community(0));
        $this->assertNull(Domain::get_community(-3));
    }

    public function test_ut_dom_002_get_user_community_role_returns_null_for_invalid_inputs(): void
    {
        $this->assertNull(Domain::get_user_community_role(0, 1));
        $this->assertNull(Domain::get_user_community_role(5, 0));
    }

    public function test_ut_dom_002_get_user_community_role_rejects_unlisted_role(): void
    {
        $this->wpdb->queue_get_var('superadmin');

        $this->assertNull(Domain::get_user_community_role(5, 1));
    }

    public function test_ut_dom_003_global_capability_short_circuits_community_checks(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $this->assertTrue(Domain::current_user_can_moderate_community(999));
        $this->assertTrue(Domain::current_user_can_view_events_for_community(999));
        $this->assertTrue(Domain::current_user_can_publish_guides_for_community(999));
    }

    public function test_ut_dom_003_admin_local_role_grants_manage_for_assigned_community(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(7);
        $this->wpdb->queue_get_var('admin');

        $this->assertTrue(Domain::current_user_can_manage_community(1));
    }

    public function test_ut_dom_003_moderator_local_role_does_not_grant_manage(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(7);
        // Two calls to get_user_community_role (manage + moderate) consume two get_var values.
        $this->wpdb->queue_get_var('moderator');
        $this->wpdb->queue_get_var('moderator');

        $this->assertFalse(Domain::current_user_can_manage_community(1));
        $this->assertTrue(Domain::current_user_can_moderate_community(1));
    }

    public function test_ut_dom_003_member_role_cannot_publish_guides_nor_moderate(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(7);
        $this->wpdb->queue_get_var('member');

        $this->assertFalse(Domain::current_user_can_manage_community(1));
        $this->assertFalse(Domain::current_user_can_moderate_community(1));
        $this->assertFalse(Domain::current_user_can_publish_guides_for_community(1));
        $this->assertFalse(Domain::current_user_can_view_events_for_community(1));
    }

    public function test_ut_dom_003_any_admin_area_is_true_when_any_capability_present(): void
    {
        Functions\when('current_user_can')->alias(static fn($cap) => $cap === Capabilities::VIEW_EVENTS);
        Functions\when('get_current_user_id')->justReturn(0);

        $this->assertTrue(Domain::current_user_can_access_any_admin_area());
    }

    public function test_ut_dom_004_community_scope_sql_strips_non_positive_and_text_ids(): void
    {
        // absint converts -1 to 1 and 'evil' to 0; only zeros are filtered out.
        $this->assertSame('goals.community_id IN (1,1,2,2)', Domain::community_scope_sql('goals.community_id', [-1, 0, 1, '2', 2, 'evil']));
    }

    public function test_ut_dom_004_community_scope_sql_empty_returns_impossible_clause(): void
    {
        $this->assertSame('1 = 0', Domain::community_scope_sql('goals.community_id', []));
        $this->assertSame('1 = 0', Domain::community_scope_sql('goals.community_id', [0, '0']));
    }

    public function test_ut_dom_005_get_active_goal_returns_null_for_non_positive_id(): void
    {
        $this->assertNull(Domain::get_active_goal(0));
        $this->assertNull(Domain::get_active_goal(-1));
    }

    public function test_ut_dom_005_get_active_goal_uses_community_scope_when_provided(): void
    {
        $this->wpdb->queue_get_row((object) ['id' => 11]);

        Domain::get_active_goal(11, 1);

        $this->assertSqlContainsInOneCall(['cg_goals', 'community_id', 'status', 'active']);
    }

    public function test_ut_dom_005_get_visible_contribution_query_uses_public_positive_list(): void
    {
        $this->wpdb->queue_get_row(null);

        Domain::get_visible_contribution(5);

        $this->assertSqlContainsInOneCall(["'open'", "'in_progress'", "'resolved'"]);
    }

    public function test_ut_dom_006_rate_limit_allows_max_then_rejects_within_window(): void
    {
        Functions\when('get_option')->justReturn(2);
        $transient = [];
        Functions\when('get_transient')->alias(static function ($key) use (&$transient) {
            return $transient[$key] ?? false;
        });
        Functions\when('set_transient')->alias(static function ($key, $value, $ttl) use (&$transient) {
            $transient[$key] = $value;
            return true;
        });
        Functions\when('get_current_user_id')->justReturn(7);

        $this->assertTrue(Domain::check_rate_limit('contribution'));
        $this->assertTrue(Domain::check_rate_limit('contribution'));
        $this->assertFalse(Domain::check_rate_limit('contribution'));
    }

    public function test_ut_dom_006_rate_limit_keys_isolate_by_action_and_user(): void
    {
        $transient = [];
        Functions\when('get_transient')->alias(static function ($key) use (&$transient) {
            return $transient[$key] ?? false;
        });
        Functions\when('set_transient')->alias(static function ($key, $value, $ttl) use (&$transient) {
            $transient[$key] = $value;
            return true;
        });
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\when('get_option')->justReturn(1);

        $this->assertTrue(Domain::check_rate_limit('contribution'));
        // Different action must not share the counter.
        $this->assertTrue(Domain::check_rate_limit('response'));
        // Same action now exhausted.
        $this->assertFalse(Domain::check_rate_limit('contribution'));
    }

    public function test_ut_dom_006_rate_limit_invalid_max_falls_back_to_constant(): void
    {
        Functions\when('get_option')->justReturn(0);
        $this->assertSame(Domain::RATE_LIMIT_MAX, Domain::rate_limit_max());
    }

    public function test_ut_dom_007_honeypot_disabled_never_triggers(): void
    {
        Functions\when('get_option')->justReturn(0);
        $_POST['cg_website'] = 'filled';

        $this->assertFalse(Domain::honeypot_triggered());
    }

    public function test_ut_dom_007_honeypot_enabled_triggers_when_field_filled(): void
    {
        Functions\when('get_option')->justReturn(1);
        $_POST['cg_website'] = 'spam link';

        $this->assertTrue(Domain::honeypot_triggered());
    }

    public function test_ut_dom_007_spam_threshold_is_exactly_five_links(): void
    {
        Functions\when('apply_filters')->returnArg(2);

        $four = 'http://a.com http://b.com http://c.com http://d.com';
        $this->assertFalse(Domain::is_spam($four . ' plain', 'contribution'));

        $five = $four . ' http://e.com';
        $this->assertTrue(Domain::is_spam($five, 'contribution'));
    }

    public function test_ut_dom_007_spam_filter_can_override_heuristic(): void
    {
        Functions\when('apply_filters')->alias(static fn($tag, $value) => true);

        $this->assertTrue(Domain::is_spam('clean content with no links', 'contribution'));
    }

    public function test_ut_dom_008_valid_transitions_matrix(): void
    {
        $this->assertTrue(Domain::is_valid_transition('pending', 'pending'));
        $this->assertTrue(Domain::is_valid_transition('pending', 'open'));
        $this->assertTrue(Domain::is_valid_transition('pending', 'spam'));
        $this->assertTrue(Domain::is_valid_transition('pending', 'hidden'));
        $this->assertTrue(Domain::is_valid_transition('open', 'resolved'));
        $this->assertTrue(Domain::is_valid_transition('resolved', 'open'));
        $this->assertTrue(Domain::is_valid_transition('hidden', 'pending'));
    }

    public function test_ut_dom_008_invalid_transitions_are_rejected(): void
    {
        $this->assertFalse(Domain::is_valid_transition('pending', 'resolved'));
        $this->assertFalse(Domain::is_valid_transition('pending', 'in_progress'));
        $this->assertFalse(Domain::is_valid_transition('open', 'pending'));
        $this->assertFalse(Domain::is_valid_transition('resolved', 'spam'));
        $this->assertFalse(Domain::is_valid_transition('bogus', 'open'));
        $this->assertFalse(Domain::is_valid_transition('pending', 'bogus'));
    }

    public function test_ut_dom_008_public_response_statuses_is_strictly_published(): void
    {
        $this->assertSame(['published'], Domain::public_response_statuses());
        $this->assertSame(['pending', 'published', 'spam', 'hidden'], Domain::response_statuses());
    }
}
