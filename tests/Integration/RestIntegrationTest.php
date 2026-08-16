<?php
/**
 * REST integration tests (spec 03): contracts, visibility, write paths and
 * pagination edge cases, dispatched through the real WP REST server.
 *
 * @package CommonGoals\Tests\Integration
 */

namespace CommonGoals\Tests\Integration;

use CommonGoals\Database;
use CommonGoals\Domain;
use CommonGoals\Tests\Integration\Support\IntegrationTestCase;

final class RestIntegrationTest extends IntegrationTestCase
{
    public function test_int_rest_001_communities_expose_only_allowlist_fields(): void
    {
        $this->create_community(['name' => 'Alpha', 'slug' => 'alpha', 'created_by' => 999]);
        $this->create_community(['name' => 'Hidden', 'slug' => 'hidden', 'status' => 'inactive']);

        $response = $this->dispatch('GET', '/common-goals/v1/communities');

        $this->assertSame(200, $response->get_status());
        $rows = $response->get_data();
        $this->assertCount(1, $rows, 'Only active communities must be returned');
        $row = (object) $rows[0];
        $this->assertSame('Alpha', $row->name);
        $this->assertFalse(property_exists($row, 'created_by'), 'created_by must never be exposed');
    }

    public function test_int_rest_001_contributions_only_return_public_statuses(): void
    {
        $seed = $this->seed_with_contributions();

        $response = $this->dispatch('GET', '/common-goals/v1/contributions', ['goal_id' => $seed->goal_id]);

        $statuses = array_unique(array_map(static fn($r) => $r->status, $response->get_data()));
        foreach ($statuses as $status) {
            $this->assertContains($status, Domain::PUBLIC_STATUSES);
        }
        $this->assertNotContains('pending', $statuses);
        $this->assertNotContains('spam', $statuses);
    }

    public function test_int_rest_002_guest_post_creates_pending_contribution(): void
    {
        wp_set_current_user(0);
        update_option('common_goals_allow_guest_posting', 1);
        $goal = $this->seed_goal();

        $response = $this->dispatch('POST', '/common-goals/v1/contributions', [
            'goal_id' => $goal->id,
            'type' => 'question',
            'title' => 'REST guest',
            'body' => 'Body',
        ]);

        $this->assertSame(201, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('pending', $data['status']);
        global $wpdb;
        $row = $wpdb->get_row("SELECT * FROM " . Database::contributions_table() . " WHERE id = " . (int) $data['id']);
        $this->assertSame(0, (int) $row->user_id);
    }

    public function test_int_rest_002_authenticated_post_creates_open_contribution(): void
    {
        $this->act_as_subscriber();
        $goal = $this->seed_goal();

        $response = $this->dispatch('POST', '/common-goals/v1/contributions', [
            'goal_id' => $goal->id,
            'type' => 'question',
            'title' => 'REST user',
            'body' => 'Body',
        ]);

        $this->assertSame(201, $response->get_status());
        $this->assertSame('open', $response->get_data()['status']);
    }

    public function test_int_rest_002_invalid_goal_returns_400(): void
    {
        $this->act_as_subscriber();

        $response = $this->dispatch('POST', '/common-goals/v1/contributions', [
            'goal_id' => 999999,
            'type' => 'question',
            'title' => 'x',
            'body' => 'y',
        ]);

        $this->assertSame(400, $response->get_status());
    }

    public function test_int_rest_003_per_page_zero_does_not_fatal(): void
    {
        $seed = $this->seed_with_contributions();

        $response = $this->dispatch('GET', '/common-goals/v1/contributions', ['per_page' => 0]);

        $this->assertSame(200, $response->get_status());
        $this->assertNotEmpty($response->get_data());
    }

    private function dispatch(string $method, string $route, array $params = [])
    {
        $request = new \WP_REST_Request($method, $route);
        $request->set_query_params($params);
        if ($method === 'POST') {
            $request->set_body_params($params);
        }
        return rest_do_request($request);
    }

    private function seed_goal(): object
    {
        $community = $this->create_community();
        return $this->create_goal((int) $community->id);
    }

    private function seed_with_contributions(): object
    {
        global $wpdb;
        $goal = $this->seed_goal();
        $now = current_time('mysql');
        foreach (['open', 'pending', 'spam'] as $status) {
            $wpdb->insert(Database::contributions_table(), [
                'goal_id' => $goal->id, 'user_id' => 0, 'type' => 'question', 'status' => $status, 'topic' => '', 'title' => $status, 'body' => 'b', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        return (object) ['goal_id' => $goal->id];
    }
}
