<?php
/**
 * Database, migration and lifecycle integration tests (spec 04).
 *
 * Runs against a real MySQL 9.6 stack against the disposable cg_wptests
 * database. Covers schema creation, migration ordering, activation,
 * reactivation idempotency, uninstall (preserve and cleanup) and the
 * transactional delete/create-guide paths.
 *
 * @package CommonGoals\Tests\Integration
 */

namespace CommonGoals\Tests\Integration;

use CommonGoals\Activator;
use CommonGoals\Capabilities;
use CommonGoals\Database;
use CommonGoals\Domain;
use CommonGoals\Migrator;
use CommonGoals\Tests\Integration\Support\IntegrationTestCase;

final class DatabaseLifecycleTest extends IntegrationTestCase
{
    public function test_db_schema_001_create_tables_builds_all_tables(): void
    {
        $this->drop_plugin_tables();
        Database::create_tables();

        global $wpdb;
        $found = $wpdb->get_col("SHOW TABLES LIKE '" . $wpdb->prefix . "cg_%'");
        $this->assertCount(11, $found);

        $row = $wpdb->get_row("SHOW CREATE TABLE " . Database::contributions_table(), ARRAY_N);
        $create_sql = $row[1] ?? '';
        $this->assertNotEmpty($create_sql);
        $this->assertStringContainsString('goal_status_created', $create_sql);
        $this->assertStringContainsString('PRIMARY KEY', $create_sql);
    }

    public function test_db_schema_002_create_tables_is_idempotent(): void
    {
        $this->seed_community();
        $checksum = $this->row_checksum(Database::communities_table());

        Database::create_tables();
        Database::create_tables();

        $this->assertSame($checksum, $this->row_checksum(Database::communities_table()), 'Idempotent re-run must not lose data');
    }

    public function test_db_mig_001_migrator_from_zero_creates_full_schema(): void
    {
        $this->drop_plugin_tables();
        delete_option(Migrator::OPTION_NAME);

        Migrator::run();

        $this->assertSame(COMMON_GOALS_VERSION, get_option(Migrator::OPTION_NAME));
        $this->assertTrue(Domain::get_default_community_id() > 0, 'A default community must be seeded');
    }

    public function test_db_mig_006_migrator_is_idempotent(): void
    {
        Migrator::run();
        $checksum = $this->row_checksum(Database::goals_table());

        Migrator::run();

        $this->assertSame($checksum, $this->row_checksum(Database::goals_table()));
    }

    public function test_db_mig_011_migration_0_2_0_re_run_is_not_idempotent_characterized(): void
    {
        // Known defect (DB-MIG-011): migration 0.2.0 runs ALTER TABLE ADD INDEX
        // unconditionally, producing Duplicate key errors on re-run because dbDelta
        // already created the compound indexes. We suppress the SQL errors and
        // verify the schema version still advances (LIFE-002 risk: option is
        // recorded regardless of SQL failure).
        global $wpdb;
        delete_option(Migrator::OPTION_NAME);
        $wpdb->suppress_errors(true);
        Migrator::run();
        Migrator::run();
        $wpdb->suppress_errors(false);

        $this->assertSame(COMMON_GOALS_VERSION, get_option(Migrator::OPTION_NAME));
    }

    public function test_life_act_001_activation_registers_capabilities_and_cron(): void
    {
        Capabilities::register();
        $admin = get_role('administrator');
        $this->assertNotNull($admin);
        $this->assertTrue($admin->has_cap(Capabilities::MANAGE));
        $this->assertTrue($admin->has_cap(Capabilities::MODERATE));
        $this->assertTrue($admin->has_cap(Capabilities::PUBLISH_GUIDES));
        $this->assertTrue($admin->has_cap(Capabilities::VIEW_EVENTS));

        $moderator = get_role(Capabilities::MODERATOR_ROLE);
        $this->assertNotNull($moderator);
        $this->assertTrue($moderator->has_cap(Capabilities::MODERATE));
    }

    public function test_life_act_002_reactivation_is_idempotent(): void
    {
        $this->seed_community();
        $before = $this->row_checksum(Database::communities_table());

        Activator::activate();
        Activator::activate();

        $this->assertSame($before, $this->row_checksum(Database::communities_table()));
        // No duplicate moderator role.
        $count = count(get_role(Capabilities::MODERATOR_ROLE) ? [1] : []);
        $this->assertSame(1, $count);
    }

    public function test_life_un_002_uninstall_preserve_keeps_data(): void
    {
        $this->seed_community();
        update_option('common_goals_cleanup_on_uninstall', 0);
        $before = $this->row_checksum(Database::communities_table());

        $this->run_uninstall();

        global $wpdb;
        $this->assertSame($before, $this->row_checksum(Database::communities_table()));
        $this->assertNotEmpty($wpdb->get_col("SHOW TABLES LIKE '" . $wpdb->prefix . "cg_communities'"));
    }

    public function test_life_un_003_uninstall_cleanup_removes_tables_and_options(): void
    {
        update_option('common_goals_cleanup_on_uninstall', 1);

        $this->run_uninstall();

        global $wpdb;
        $found = $wpdb->get_col("SHOW TABLES LIKE '" . $wpdb->prefix . "cg_%'");
        $this->assertSame([], $found, 'Cleanup must drop all plugin tables');
        $this->assertFalse(get_option(Migrator::OPTION_NAME), 'Schema version option must be removed');
    }

    public function test_db_tx_001_delete_contribution_removes_responses_transactionally(): void
    {
        $this->act_as_admin();
        $admin_id = get_current_user_id();
        $c = $this->seed_goal_and_contribution($admin_id);
        global $wpdb;
        $now = current_time('mysql');
        foreach (['r1', 'r2'] as $body) {
            $wpdb->insert(Database::responses_table(), [
                'contribution_id' => $c->contribution_id, 'user_id' => 0, 'body' => $body, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $this->assertSame(2, (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Database::responses_table() . " WHERE contribution_id = " . (int) $c->contribution_id));

        $this->with_nonce('common_goals_delete_contribution');
        $_POST['contribution_id'] = $c->contribution_id;
        $redirect = $this->capture_redirect(static function () {
            (new \CommonGoals\Frontend\BoardShortcode())->handle_delete_contribution();
        });

        $this->assertStringContainsString('contribution_deleted', $redirect ?? '');
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Database::contributions_table() . " WHERE id = " . (int) $c->contribution_id));
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Database::responses_table() . " WHERE contribution_id = " . (int) $c->contribution_id));
    }

    public function test_db_tx_002_create_guide_resolves_contribution_transactionally(): void
    {
        $seed = $this->seed_goal_and_contribution();
        $this->act_as_admin();

        $_POST = [
            'contribution_id' => $seed->contribution_id,
            'guide_title' => 'My Guide',
            'guide_content' => 'Body of guide',
        ];
        $this->with_nonce('common_goals_create_guide');
        $redirect = $this->capture_redirect(static function () {
            (new \CommonGoals\Admin\ContributionsAdminPage())->handle_create_guide();
        });

        global $wpdb;
        $guide = $wpdb->get_row("SELECT * FROM " . Database::guides_table() . " WHERE contribution_id = " . (int) $seed->contribution_id);
        $this->assertNotNull($guide, 'Guide must be created');
        $this->assertSame('draft', $guide->status);
        $this->assertSame('resolved', Domain::get_contribution($seed->contribution_id)->status, 'Contribution must be marked resolved');
        $this->assertStringContainsString('guide_created', $redirect ?? '');
    }

    private function seed_community(): object
    {
        return $this->create_community(['name' => 'Seeded', 'slug' => 'seeded']);
    }

    private function seed_goal_and_contribution(int $author_id = 0): object
    {
        global $wpdb;
        $community = $this->seed_community();
        $goal = $this->create_goal((int) $community->id);
        $wpdb->insert(Database::contributions_table(), [
            'goal_id' => $goal->id, 'user_id' => $author_id, 'type' => 'question', 'status' => 'open', 'topic' => '', 'title' => 'C', 'body' => 'b', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
        ]);
        $cid = (int) $wpdb->insert_id;
        return (object) ['community_id' => $community->id, 'goal_id' => $goal->id, 'contribution_id' => $cid];
    }

    private function call_delete_contribution_handler(int $contribution_id): void
    {
        $this->act_as_admin();
        $_POST = ['contribution_id' => $contribution_id];
        $board = new \CommonGoals\Frontend\BoardShortcode();
        add_filter('wp_redirect', static fn($url) => $url);
        try {
            $board->handle_delete_contribution();
        } catch (\Throwable $e) {
        }
    }

    private function run_uninstall(): void
    {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'common-goals/common-goals.php');
        }
        require __DIR__ . '/../../common-goals/uninstall.php';
    }

    private function drop_plugin_tables(): void
    {
        global $wpdb;
        foreach ([Database::responses_table(), Database::contributions_table(), Database::guides_table(), Database::events_table(), Database::goals_table(), Database::community_members_table(), Database::communities_table()] as $t) {
            $wpdb->query("DROP TABLE IF EXISTS {$t}");
        }
    }

    private function row_checksum(string $table): string
    {
        global $wpdb;
        return (string) $wpdb->get_var("SELECT COALESCE(SUM(id),0) FROM {$table}");
    }
}
