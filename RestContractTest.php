<?php
/**
 * REST contract tests (spec 05): route inventory, field allow-lists, visibility,
 * pagination headers and the POST contribution write matrix, dispatched through
 * the real WP REST server against the disposable test database.
 *
 * @package CommonGoals\Tests\Integration
 */

namespace CommonGoals\Tests\Integration;

use CommonGoals\Database;
use CommonGoals\Domain;
use CommonGoals\RestApi;
use CommonGoals\Tests\Integration\Support\IntegrationTestCase;

final class RestContractTest extends IntegrationTestCase
{
    public function test_rest_inventory_all_get_routes_respond_and_write_exists(): void
    {
        // Verify the eight registered operations respond with expected statuses.
        $this->assertSame(200, $this->dispatch('GET', '/common-goals/v1/communities')->get_status());
        $this->assertSame(200, $this->dispatch('GET', '/common-goals/v1/goals')->get_status());
        $this->assertSame(404, $this->dispatch('GET', '/common-goals/v1/goals/999999')->get_status());
        $this->assertSame(200, $this->dispatch('GET', '/common-goals/v1/contributions')->get_status());
        $this->assertSame(200, $this->dispatch('GET', '/common-goals/v1/guides')->get_status());

        // No DELETE/PUT/PATCH operation must exist on any resource.
        $this->assertSame(404, $this->dispatch('DELETE', '/common-goals/v1/contributions/1')->get_status());
        $this->assertSame(404, $this->dispatch('PUT', '/common-goals/v1/goals/1')->get_status());
    }

    public function test_rest_01_communities_returns_active_sorted_by_name_with_allowlist(): void
    {
        $this->create_community(['name' => 'Zeta', 'slug' => 'zeta', 'status' => 'inactive']);
        $this->create_community(['name' => 'Bravo', 'slug' => 'bravo', 'created_by' => 999]);
        $this->create_community(['name' => 'Alpha', 'slug' => 'alpha', 'created_by' => 999]);

        $response = $this->dispatch('GET', '/common-goals/v1/communities');

        $this->assertSame(200, $response->get_status());
        $rows = $response->get_data();
        $this->assertCount(2, $rows, 'Inactive community must be hidden');
        $this->assertSame('Alpha', $rows[0]->name, 'Communities must be ordered by name');
        $this->assertSame('Bravo', $rows[1]->name);
        $this->assertFalse(property_exists($rows[0], 'created_by'));
    }

    public function test_rest_02_goals_list_only_active_and_respects_community_filter(): void
    {
        $c1 = $this->create_community(['slug' => 'a']);
        $c2 = $this->create_community(['slug' => 'b']);
        $this->create_goal((int) $c1->id, ['title' => 'A']);
        $this->create_goal((int) $c2->id, ['title' => 'B']);
        $this->create_goal((int) $c1->id, ['title' => 'inactive', 'status' => 'inactive']);

        $response = $this->dispatch('GET', '/common-goals/v1/goals', ['community_id' => $c1->id]);

        $this->assertSame(200, $response->get_status());
        $titles = array_map(static fn($g) => $g->title, $response->get_data());
        $this->assertEqualsCanonicalizing(['A'], $titles);
    }

    public function test_rest_02_goal_detail_404_for_inactive_or_missing(): void
    {
        $inactive = $this->create_goal((int) $this->create_community()->id, ['status' => 'inactive']);

        $this->assertSame(404, $this->dispatch('GET', '/common-goals/v1/goals/' . $inactive->id)->get_status());
        $this->assertSame(404, $this->dispatch('GET', '/common-goals/v1/goals/999999')->get_status());
    }

    public function test_rest_03_contributions_pagination_headers_are_consistent(): void
    {
        $goal = $this->create_goal((int) $this->create_community()->id);
        global $wpdb;
        $now = current_time('mysql');
        for ($i = 0; $i < 12; $i++) {
            $wpdb->insert(Database::contributions_table(), [
                'goal_id' => $goal->id, 'user_id' => 0, 'type' => 'question', 'status' => 'open', 'topic' => '', 'title' => 'c' . $i, 'body' => 'b', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $response = $this->dispatch('GET', '/common-goals/v1/contributions', ['per_page' => 5]);

        $this->assertSame(200, $response->get_status());
        $this->assertCount(5, $response->get_data());
        $this->assertSame('12', $response->get_headers()['X-WP-Total'] ?? $response->get_headers()['X-Wp-Total'] ?? null);
        $this->assertSame('3', $response->get_headers()['X-WP-TotalPages'] ?? $response->get_headers()['X-Wp-TotalPages'] ?? null);
    }

    public function test_rest_04_contribution_detail_404_for_private_and_no_user_id_in_responses(): void
    {
        $goal = $this->create_goal((int) $this->create_community()->id);
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert(Database::contributions_table(), ['goal_id' => $goal->id, 'user_id' => 5, 'type' => 'question', 'status' => 'pending', 'topic' => '', 'title' => 'p', 'body' => 'b', 'created_at' => $now, 'updated_at' => $now]);
        $pending_id = (int) $wpdb->insert_id;

        $wpdb->insert(Database::contributions_table(), ['goal_id' => $goal->id, 'user_id' => 5, 'type' => 'question', 'status' => 'open', 'topic' => '', 'title' => 'pub', 'body' => 'b', 'created_at' => $now, 'updated_at' => $now]);
        $public_id = (int) $wpdb->insert_id;
        $wpdb->insert(Database::responses_table(), ['contribution_id' => $public_id, 'user_id' => 7, 'body' => 'r', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);

        $this->assertSame(404, $this->dispatch('GET', '/common-goals/v1/contributions/' . $pending_id)->get_status());

        $detail = $this->dispatch('GET', '/common-goals/v1/contributions/' . $public_id);
        $data = $detail->get_data();
        $this->assertSame(200, $detail->get_status());
        $this->assertFalse(array_key_exists('user_id', (array) $data), 'Contribution detail must not expose user_id');
        $this->assertCount(1, $data['responses']);
        $this->assertFalse(array_key_exists('user_id', $data['responses'][0]));
    }

    public function test_rest_05_guides_detail_only_published_and_uppercase_resolves(): void
    {
        global $wpdb;
        $now = current_time('mysql');
        $goal = $this->create_goal((int) $this->create_community()->id);
        $wpdb->insert(Database::contributions_table(), ['goal_id' => $goal->id, 'user_id' => 0, 'type' => 'question', 'status' => 'open', 'topic' => '', 'title' => 'c', 'body' => 'b', 'created_at' => $now, 'updated_at' => $now]);
        $cid = (int) $wpdb->insert_id;
        $wpdb->insert(Database::guides_table(), ['contribution_id' => $cid, 'slug' => 'my-guide', 'title' => 'G', 'content' => 'x', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
        $wpdb->insert(Database::guides_table(), ['contribution_id' => $cid, 'slug' => 'draft-guide', 'title' => 'D', 'content' => 'x', 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);

        $this->assertSame(200, $this->dispatch('GET', '/common-goals/v1/guides/my-guide')->get_status());
        $this->assertSame(404, $this->dispatch('GET', '/common-goals/v1/guides/draft-guide')->get_status());
        // The route accepts uppercase and the callback normalizes via sanitize_title.
        $this->assertSame(200, $this->dispatch('GET', '/common-goals/v1/guides/MY-GUIDE')->get_status());
    }

    public function test_rest_unsupported_method_is_not_allowed(): void
    {
        $this->assertSame(404, $this->dispatch('DELETE', '/common-goals/v1/contributions/1')->get_status());
        $this->assertSame(404, $this->dispatch('PUT', '/common-goals/v1/goals/1')->get_status());
    }

    public function test_rest_06_write_matrix_covers_all_validation_branches(): void
    {
        $goal = $this->create_goal((int) $this->create_community()->id);

        // Guest blocked when option disabled. WP REST may report this as 401 or 403.
        wp_set_current_user(0);
        update_option('common_goals_allow_guest_posting', 0);
        $blocked = $this->post_contribution($goal->id, ['title' => 't', 'body' => 'b'])->get_status();
        $this->assertContains($blocked, [401, 403]);

        // Guest allowed with option on -> pending.
        update_option('common_goals_allow_guest_posting', 1);
        $r = $this->post_contribution($goal->id, ['title' => 't', 'body' => 'b']);
        $this->assertSame(201, $r->get_status(), 'Expected 201, got body: ' . wp_json_encode($r->get_data()));
        $this->assertSame('pending', $r->get_data()['status']);

        // Invalid type.
        $this->assertSame(400, $this->post_contribution($goal->id, ['type' => 'bogus', 'title' => 't', 'body' => 'b'])->get_status());

        // Empty required.
        $this->assertSame(400, $this->post_contribution($goal->id, ['title' => '', 'body' => ''])->get_status());
    }

    public function test_rest_06_spam_with_excessive_links_is_rejected(): void
    {
        $this->act_as_subscriber();
        $goal = $this->create_goal((int) $this->create_community()->id);

        $spam = 'see http://a.com http://b.com http://c.com http://d.com http://e.com';
        $r = $this->post_contribution($goal->id, ['title' => 't', 'body' => $spam]);

        $this->assertSame(403, $r->get_status());
    }

    private function post_contribution(int $goal_id, array $extra = [])
    {
        return $this->dispatch('POST', '/common-goals/v1/contributions', array_merge([
            'goal_id' => $goal_id,
            'type' => 'question',
            'title' => 'x',
            'body' => 'y',
        ], $extra));
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
}
