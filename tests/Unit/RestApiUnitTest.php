<?php
/**
 * Unit tests for the REST API layer.
 *
 * Covers spec cases UT-API-001 to UT-API-005. Uses the RequestStub and a
 * WpdbSpy to assert route registration, field allow-lists, public visibility,
 * pagination headers and write-side validation branches.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use Brain\Monkey\Functions;
use CommonGoals\Domain;
use CommonGoals\RestApi;
use CommonGoals\Tests\Unit\Support\RequestStub;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class RestApiUnitTest extends UnitTestCase
{
    public function test_ut_api_001_register_routes_registers_expected_operations(): void
    {
        $routes = [];
        Functions\when('register_rest_route')->alias(static function ($ns, $route, $args) use (&$routes) {
            $routes[] = $ns . $route . ':' . $args['methods'];
        });

        RestApi::register_routes();

        $this->assertContains('common-goals/v1/communities:GET', $routes);
        $this->assertContains('common-goals/v1/goals:GET', $routes);
        $this->assertContains('common-goals/v1/goals/(?P<id>\\d+):GET', $routes);
        $this->assertContains('common-goals/v1/contributions:GET', $routes);
        $this->assertContains('common-goals/v1/contributions/(?P<id>\\d+):GET', $routes);
        $this->assertContains('common-goals/v1/contributions:POST', $routes);
        $this->assertContains('common-goals/v1/vote:POST', $routes);
        $this->assertContains('common-goals/v1/bookmark:POST', $routes);
        $this->assertContains('common-goals/v1/notifications/read:POST', $routes);
        $this->assertContains('common-goals/v1/guides:GET', $routes);
        $this->assertContains('common-goals/v1/guides/(?P<slug>[a-zA-Z0-9\\-]+):GET', $routes);
        $this->assertCount(11, $routes, 'Exactly eleven REST operations must be registered');
    }

    public function test_ut_api_001_namespace_constant_is_stable(): void
    {
        $this->assertSame('common-goals/v1', RestApi::NAMESPACE);
    }

    public function test_ut_api_002_get_goals_returns_active_goals_with_allowlist_fields(): void
    {
        $this->wpdb->queue_get_results([
            (object) ['id' => 1, 'community_id' => 1, 'title' => 'Goal', 'description' => 'd', 'beneficiary' => 'b', 'status' => 'active', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-02'],
        ]);

        $response = RestApi::get_goals(new RequestStub(['community_id' => 1]));

        $this->assertSame(200, $response->get_status());
        $this->assertSqlContainsInOneCall(['cg_goals', 'status', 'active', 'community_id']);
        $row = $response->get_data()[0];
        $this->assertFalse(property_exists($row, 'created_by'));
        $this->assertFalse(property_exists($row, 'alignment_rules'));
    }

    public function test_ut_api_002_get_goal_returns_resolved_allowed_types(): void
    {
        Functions\when('apply_filters')->returnArg(2);
        $this->wpdb->queue_get_row((object) [
            'id' => 1, 'community_id' => 1, 'title' => 'G', 'description' => '', 'beneficiary' => '',
            'allowed_contribution_types' => json_encode(['question']), 'status' => 'active',
            'created_at' => '', 'updated_at' => '',
        ]);

        $response = RestApi::get_goal(new RequestStub(['id' => '1']));

        $data = $response->get_data();
        $this->assertSame(200, $response->get_status());
        $this->assertSame(['question'], $data['allowed_contribution_types']);
        $this->assertArrayNotHasKey('created_by', $data);
    }

    public function test_ut_api_002_get_goal_returns_404_when_goal_missing(): void
    {
        $this->wpdb->queue_get_row(null);

        $response = RestApi::get_goal(new RequestStub(['id' => '99']));

        $this->assertSame(404, $response->get_status());
    }

    public function test_ut_api_003_get_contributions_only_returns_public_statuses(): void
    {
        $this->wpdb->queue_get_results([
            (object) ['id' => 1, 'goal_id' => 1, 'community_id' => 1, 'type' => 'question', 'status' => 'open', 'topic' => '', 'title' => '', 'body' => '', 'created_at' => '', 'updated_at' => ''],
        ]);
        $this->wpdb->queue_get_var('1');

        $response = RestApi::get_contributions(new RequestStub([]));

        $this->assertSame(200, $response->get_status());
        $this->assertSqlContainsInOneCall(["'open'", "'in_progress'", "'resolved'"]);
        $this->assertSqlNotContains('pending');
        $this->assertSame('1', $response->headers['X-WP-Total']);
        $this->assertNotEmpty($response->headers['X-WP-TotalPages']);
    }

    public function test_ut_api_003_get_contributions_invalid_type_filter_is_ignored(): void
    {
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_var('0');

        RestApi::get_contributions(new RequestStub(['type' => 'evil; DROP']));

        // The invalid type must not appear as a WHERE clause value.
        $found_evil = false;
        foreach ($this->wpdb->sql_strings() as $sql) {
            if (stripos($sql, 'evil') !== false) {
                $found_evil = true;
            }
        }
        $this->assertFalse($found_evil);
    }

    public function test_ut_api_005_get_guides_limits_to_50_and_published_only(): void
    {
        $this->wpdb->queue_get_results([]);

        RestApi::get_guides(new RequestStub(['per_page' => 999]));

        $this->assertSqlContainsInOneCall(["guides.status", 'published', 'LIMIT']);
        // LIMIT must be 50 even though per_page was 999.
        $has_limit_50 = false;
        foreach ($this->wpdb->sql_strings() as $sql) {
            if (preg_match('/LIMIT\s+50\b/', $sql)) {
                $has_limit_50 = true;
            }
        }
        $this->assertTrue($has_limit_50);
    }

    public function test_ut_api_005_get_guide_returns_404_when_not_found(): void
    {
        $this->wpdb->queue_get_row(null);

        $response = RestApi::get_guide(new RequestStub(['slug' => 'missing']));

        $this->assertSame(404, $response->get_status());
    }

    public function test_ut_api_004_create_contribution_validates_goal_first(): void
    {
        $this->wpdb->queue_get_row(null);

        $response = RestApi::create_contribution(new RequestStub([
            'goal_id' => 0,
            'type' => 'question',
            'title' => 'Hi',
            'body' => 'Body',
        ]));

        $this->assertSame(400, $response->get_status());
        $this->assertSame(0, $this->wpdb->count_method('insert'));
    }

    public function test_ut_api_004_create_contribution_rejects_disallowed_type(): void
    {
        Functions\when('apply_filters')->returnArg(2);
        $this->wpdb->queue_get_row((object) ['id' => 1, 'community_id' => 1, 'allowed_contribution_types' => json_encode(['question']), 'status' => 'active']);

        $response = RestApi::create_contribution(new RequestStub([
            'goal_id' => 1, 'type' => 'resource', 'title' => 'T', 'body' => 'B',
        ]));

        $this->assertSame(400, $response->get_status());
    }

    public function test_ut_api_004_create_contribution_rejects_empty_required_fields(): void
    {
        Functions\when('apply_filters')->returnArg(2);
        $this->wpdb->queue_get_row((object) ['id' => 1, 'community_id' => 1, 'allowed_contribution_types' => '', 'status' => 'active']);

        $response = RestApi::create_contribution(new RequestStub([
            'goal_id' => 1, 'type' => 'question', 'title' => '', 'body' => '',
        ]));

        $this->assertSame(400, $response->get_status());
    }

    public function test_ut_api_004_create_contribution_rejects_guest_when_option_disabled(): void
    {
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('get_option')->justReturn(0);
        $this->wpdb->queue_get_row((object) ['id' => 1, 'community_id' => 1, 'allowed_contribution_types' => '', 'status' => 'active']);

        $response = RestApi::create_contribution(new RequestStub([
            'goal_id' => 1, 'type' => 'question', 'title' => 'T', 'body' => 'B',
        ]));

        $this->assertSame(403, $response->get_status());
        $this->assertSame(0, $this->wpdb->count_method('insert'));
    }

    public function test_ut_api_004_create_contribution_enforces_rate_limit(): void
    {
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('get_option')->alias(static fn($name, $default = false) => $name === 'common_goals_allow_guest_posting' ? 1 : $default);
        Functions\when('get_transient')->justReturn(99);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(0);
        $this->wpdb->queue_get_row((object) ['id' => 1, 'community_id' => 1, 'allowed_contribution_types' => '', 'status' => 'active']);

        $response = RestApi::create_contribution(new RequestStub([
            'goal_id' => 1, 'type' => 'question', 'title' => 'T', 'body' => 'B',
        ]));

        $this->assertSame(429, $response->get_status());
        $this->assertSame(0, $this->wpdb->count_method('insert'));
    }

    public function test_ut_api_004_create_contribution_success_for_authenticated_user(): void
    {
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        $this->wpdb->queue_get_row((object) ['id' => 1, 'community_id' => 1, 'allowed_contribution_types' => '', 'status' => 'active']);

        $response = RestApi::create_contribution(new RequestStub([
            'goal_id' => 1, 'type' => 'question', 'title' => 'Title', 'body' => 'Body',
        ]));

        $this->assertSame(201, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('open', $data['status']);
        $this->assertSame(1, did_action('common_goals_contribution_created'));
    }

    public function test_ut_api_004_create_contribution_db_failure_returns_500(): void
    {
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        $this->wpdb->queue_get_row((object) ['id' => 1, 'community_id' => 1, 'allowed_contribution_types' => '', 'status' => 'active']);
        $this->wpdb->queue_insert(false);

        $response = RestApi::create_contribution(new RequestStub([
            'goal_id' => 1, 'type' => 'question', 'title' => 'Title', 'body' => 'Body',
        ]));

        $this->assertSame(500, $response->get_status());
    }

    public function test_vote_permission_requires_login(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        $this->assertFalse(RestApi::check_vote_permission());

        Functions\when('is_user_logged_in')->justReturn(true);
        $this->assertTrue(RestApi::check_vote_permission());
    }

    public function test_cast_vote_rejects_invalid_targets(): void
    {
        $response = RestApi::cast_vote(new RequestStub([
            'object_type' => 'invalid',
            'object_id'   => 1,
            'value'       => 1,
        ]));

        $this->assertSame(400, $response->get_status());
        $this->assertSame(0, $this->wpdb->count_method('insert'));
    }

    public function test_cast_vote_enforces_rate_limit_before_writing(): void
    {
        Functions\when('get_transient')->justReturn(99);
        Functions\when('get_option')->alias(static fn($name, $default = false) => $name === 'common_goals_rate_limit_max' ? 5 : $default);
        Functions\when('get_current_user_id')->justReturn(7);

        $response = RestApi::cast_vote(new RequestStub([
            'object_type' => 'contribution',
            'object_id'   => 1,
            'value'       => 1,
        ]));

        $this->assertSame(429, $response->get_status());
        $this->assertSame(0, $this->wpdb->count_method('insert'));
    }

    public function test_cast_vote_returns_domain_result(): void
    {
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        $this->wpdb->queue_get_row(null);
        $this->wpdb->queue_get_var('12');

        $response = RestApi::cast_vote(new RequestStub([
            'object_type' => 'contribution',
            'object_id'   => 1,
            'value'       => -1,
        ]));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['score' => 12, 'user_vote' => -1], $response->get_data());
    }

    public function test_toggle_bookmark_rejects_invalid_thread_and_returns_state(): void
    {
        $invalid = RestApi::toggle_bookmark(new RequestStub(['contribution_id' => 0]));
        $this->assertSame(400, $invalid->get_status());

        Functions\when('get_current_user_id')->justReturn(7);
        $this->wpdb->queue_get_var(null);

        $valid = RestApi::toggle_bookmark(new RequestStub(['contribution_id' => 8]));

        $this->assertSame(200, $valid->get_status());
        $this->assertSame(['bookmarked' => true], $valid->get_data());
    }

    public function test_mark_notifications_read_requires_login(): void
    {
        Functions\when('get_current_user_id')->justReturn(0);

        $response = RestApi::mark_notifications_read(new RequestStub(['all' => true]));

        $this->assertSame(401, $response->get_status());
        $this->assertSame(0, $this->wpdb->count_method('update'));
    }

    public function test_mark_notifications_read_can_mark_one_or_all_and_returns_unread_count(): void
    {
        Functions\when('get_current_user_id')->justReturn(7);
        $this->wpdb->queue_get_var('4');
        $one = RestApi::mark_notifications_read(new RequestStub(['id' => 11]));

        $this->wpdb->queue_get_var('0');
        $all = RestApi::mark_notifications_read(new RequestStub(['all' => true]));

        $this->assertSame(200, $one->get_status());
        $this->assertSame(['unread' => 4], $one->get_data());
        $this->assertSame(200, $all->get_status());
        $this->assertSame(['unread' => 0], $all->get_data());
        $this->assertSame(['id' => 11, 'user_id' => 7], $this->wpdb->calls[0]['extra']['where']);
        $this->assertSame(['user_id' => 7], $this->wpdb->calls[2]['extra']['where']);
    }

    private function assertSqlNotContains(string $needle): void
    {
        foreach ($this->wpdb->sql_strings() as $sql) {
            $this->assertStringNotContainsString("'{$needle}'", $sql);
        }
    }
}
