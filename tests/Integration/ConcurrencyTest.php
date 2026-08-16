<?php

namespace CommonGoals\Tests\Integration;

use CommonGoals\Capabilities;
use CommonGoals\Database;
use CommonGoals\Domain;
use CommonGoals\Tests\Integration\Support\IntegrationTestCase;

final class ConcurrencyTest extends IntegrationTestCase
{
    public function test_con_001_asset_client_lib_available(): void
    {
        $this->act_as_admin();
        $this->create_community();
        $this->assertNotNull($GLOBALS['wpdb']);
    }

    public function test_con_002_contributions_have_unique_timestamps(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);
        global $wpdb;

        $times = [];
        for ($i = 0; $i < 5; $i++) {
            $now = current_time('mysql');
            $wpdb->insert(Database::contributions_table(), [
                'goal_id' => $g->id,
                'user_id' => get_current_user_id(),
                'type' => 'question',
                'status' => 'open',
                'topic' => '',
                'title' => 'Unique ' . $i,
                'body' => 'Unique body ' . $i,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $times[] = $now;
            usleep(1000);
        }

        $this->assertCount(5, $times);
    }

    public function test_con_003_rows_visible_after_insert_without_cache(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);
        global $wpdb;

        $wpdb->insert(Database::contributions_table(), [
            'goal_id' => $g->id,
            'user_id' => get_current_user_id(),
            'type' => 'question',
            'status' => 'open',
            'topic' => '',
            'title' => 'Fresh',
            'body' => 'Fresh body',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $insert_id = (int) $wpdb->insert_id;

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . Database::contributions_table() . " WHERE id = %d", $insert_id));
        $this->assertNotNull($row);
        $this->assertSame('Fresh', $row->title);
    }

    public function test_con_004_count_matches_insert_count(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);
        global $wpdb;

        $n = 10;
        for ($i = 0; $i < $n; $i++) {
            $wpdb->insert(Database::contributions_table(), [
                'goal_id' => $g->id,
                'user_id' => get_current_user_id(),
                'type' => 'question',
                'status' => 'open',
                'topic' => '',
                'title' => 'Count ' . $i,
                'body' => 'Body ' . $i,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
        }

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Database::contributions_table());
        $this->assertSame($n, $count);
    }

    public function test_con_005_insert_then_select_transaction_read(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);
        global $wpdb;

        $wpdb->query('START TRANSACTION');
        $wpdb->insert(Database::contributions_table(), [
            'goal_id' => $g->id,
            'user_id' => get_current_user_id(),
            'type' => 'question',
            'status' => 'open',
            'topic' => '',
            'title' => 'TXN Test',
            'body' => 'Transactional',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $within = $wpdb->get_var("SELECT COUNT(*) FROM " . Database::contributions_table());
        $wpdb->query('ROLLBACK');
        $after = $wpdb->get_var("SELECT COUNT(*) FROM " . Database::contributions_table());

        $this->assertSame(1, (int) $within);
        $this->assertSame(0, (int) $after);
    }
}
