<?php

namespace CommonGoals\Tests\Integration;

use CommonGoals\Database;
use CommonGoals\Domain;
use CommonGoals\RestApi;
use CommonGoals\Tests\Integration\Support\IntegrationTestCase;

final class PerformanceTest extends IntegrationTestCase
{
    public function test_perf_001_board_query_count_seeded(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);
        $uid = get_current_user_id();
        global $wpdb;

        for ($i = 0; $i < 5; $i++) {
            $wpdb->insert(Database::contributions_table(), [
                'goal_id' => $g->id,
                'user_id' => $uid,
                'type' => 'question',
                'status' => 'open',
                'topic' => '',
                'title' => 'Entry ' . $i,
                'body' => 'Body ' . $i,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
        }

        $before = get_num_queries();
        do_shortcode('[common_goals_board goal_id="' . $g->id . '"]');
        $queries = get_num_queries() - $before;

        $this->assertLessThan(50, $queries, 'Board should stay under 50 queries');
    }

    public function test_perf_002_rest_contributions_seeded(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);
        $uid = get_current_user_id();
        global $wpdb;

        for ($i = 0; $i < 10; $i++) {
            $wpdb->insert(Database::contributions_table(), [
                'goal_id' => $g->id,
                'user_id' => $uid,
                'type' => 'question',
                'status' => 'open',
                'topic' => '',
                'title' => 'Entry ' . $i,
                'body' => 'Body ' . $i,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
        }

        $before = get_num_queries();
        $request = new \WP_REST_Request('GET', '/common-goals/v1/contributions');
        $response = rest_do_request($request);
        $queries = get_num_queries() - $before;

        $this->assertSame(200, $response->get_status());
        $this->assertLessThan(30, $queries, 'REST contributions should stay under 30 queries');
    }

    public function test_perf_003_rest_communities_query_count(): void
    {
        $this->act_as_admin();
        $this->create_community(['name' => 'A1', 'slug' => 'a1']);
        $this->create_community(['name' => 'A2', 'slug' => 'a2']);

        $before = get_num_queries();
        $request = new \WP_REST_Request('GET', '/common-goals/v1/communities');
        $response = rest_do_request($request);
        $queries = get_num_queries() - $before;

        $this->assertSame(200, $response->get_status());
        $this->assertLessThan(20, $queries, 'REST communities should stay under 20 queries');
    }

    public function test_perf_004_rest_goals_pagination_query_count(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();

        for ($i = 0; $i < 3; $i++) {
            $this->create_goal((int) $c->id, ['title' => 'Goal ' . $i]);
        }

        $before = get_num_queries();
        $request = new \WP_REST_Request('GET', '/common-goals/v1/goals');
        $request->set_query_params(['community_id' => $c->id, 'per_page' => 2]);
        $response = rest_do_request($request);
        $queries = get_num_queries() - $before;

        $this->assertSame(200, $response->get_status());
        $this->assertLessThan(20, $queries, 'REST goals should stay under 20 queries');
    }

    public function test_perf_005_memory_under_limit(): void
    {
        $mem = memory_get_usage(true);
        $this->assertLessThan(100 * 1024 * 1024, $mem, 'Memory should be under 100 MiB');
    }

    public function test_perf_006_contribution_bulk_insert_and_read(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);
        global $wpdb;

        $start = microtime(true);
        for ($i = 0; $i < 50; $i++) {
            $wpdb->insert(Database::contributions_table(), [
                'goal_id' => $g->id,
                'user_id' => get_current_user_id(),
                'type' => 'question',
                'status' => 'open',
                'topic' => '',
                'title' => 'Bulk Entry ' . $i,
                'body' => 'Bulk body content ' . $i,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
        }
        $write_time = microtime(true) - $start;

        $start = microtime(true);
        $count = $wpdb->get_var("SELECT COUNT(*) FROM " . Database::contributions_table() . " WHERE goal_id = " . (int) $g->id);
        $read_time = microtime(true) - $start;

        $this->assertSame(50, (int) $count);
        $this->assertLessThan(10, $write_time, '50 inserts should take under 10 seconds');
        $this->assertLessThan(1, $read_time, 'Count query should take under 1 second');
    }
}
