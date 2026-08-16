<?php
/**
 * Operations integration tests (spec 03): privacy exporter/eraser, email
 * notifications, JSON export, Site Health and cron scheduling.
 *
 * @package CommonGoals\Tests\Integration
 */

namespace CommonGoals\Tests\Integration;

use CommonGoals\Capabilities;
use CommonGoals\Database;
use CommonGoals\Exporter;
use CommonGoals\Notifications;
use CommonGoals\Privacy;
use CommonGoals\SiteHealth;
use CommonGoals\Tests\Integration\Support\IntegrationTestCase;

final class OperationsIntegrationTest extends IntegrationTestCase
{
    public function test_int_priv_001_personal_export_includes_user_contributions(): void
    {
        $user_id = $this->make_user('alice@cg-test.test', 'subscriber');
        $this->insert_contribution($user_id, 'open', 'Alice question');

        $result = Privacy::export_user_data('alice@cg-test.test');

        $titles = array_map(static fn($item) => $item['item_id'], $result['data']);
        $this->assertContains('cg-contribution-1', $titles);
        $this->assertTrue($result['done']);
    }

    public function test_int_priv_001_export_for_unknown_email_is_empty(): void
    {
        $result = Privacy::export_user_data('nobody@nowhere.test');
        $this->assertSame([], $result['data']);
        $this->assertTrue($result['done']);
    }

    public function test_int_priv_002_erase_anonymizes_attribution(): void
    {
        $user_id = $this->make_user('bob@cg-test.test', 'subscriber');
        $cid = $this->insert_contribution($user_id, 'open', 'Bob question');

        $result = Privacy::erase_user_data('bob@cg-test.test');

        $this->assertTrue($result['done']);
        global $wpdb;
        $row = $wpdb->get_row("SELECT * FROM " . Database::contributions_table() . " WHERE id = " . (int) $cid);
        $this->assertSame(0, (int) $row->user_id, 'user_id must be anonymized to 0');
        $this->assertSame('Bob question', $row->title, 'Content must be retained');
    }

    public function test_int_not_001_pending_contribution_notifies_moderators(): void
    {
        $this->act_as_admin();
        $cid = $this->insert_contribution(0, 'pending', 'Pending one');
        $this->reset_mailer();

        Notifications::notify_moderators_pending($cid, ['status' => 'pending']);

        $sent = $this->sent_mails();
        $this->assertGreaterThan(0, count($sent), 'Moderators must receive a mail for pending contributions');
        $this->assertStringContainsString('Pending one', $sent[0]['body']);
    }

    public function test_int_not_001_non_pending_contribution_sends_no_mail(): void
    {
        $cid = $this->insert_contribution(0, 'open', 'Open one');
        $this->reset_mailer();

        Notifications::notify_moderators_pending($cid, ['status' => 'open']);

        $this->assertSame(0, count($this->sent_mails()));
    }

    public function test_int_exp_001_export_json_contains_seven_tables_and_manifest(): void
    {
        $this->insert_contribution(0, 'open', 'Exported');
        $this->act_as_admin();

        $json = Exporter::to_json();
        $decoded = json_decode($json, true);

        $this->assertSame('1.0', $decoded['schema_version']);
        $this->assertSame(['communities', 'members', 'goals', 'contributions', 'responses', 'guides', 'events'], array_keys($decoded['tables']));
        $this->assertGreaterThan(0, $decoded['manifest']['table_counts']['contributions']);
    }

    public function test_int_health_001_tables_test_is_good_when_present(): void
    {
        $result = SiteHealth::test_tables_exist();
        $this->assertSame('good', $result['status']);
    }

    public function test_int_health_001_tables_test_is_critical_when_missing(): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE " . Database::responses_table());

        $result = SiteHealth::test_tables_exist();

        // Recreate the table so subsequent tests / tearDown have a clean schema.
        $this->ensure_responses_table();

        $this->assertSame('critical', $result['status']);
    }

    public function test_int_cron_001_activation_schedules_daily_event(): void
    {
        SiteHealth::unschedule_cron();
        SiteHealth::schedule_cron();

        $this->assertNotFalse(wp_next_scheduled(SiteHealth::CRON_HOOK));

        SiteHealth::schedule_cron();
        $timestamps = [];
        foreach (_get_cron_array() as $time => $hooks) {
            if (isset($hooks[SiteHealth::CRON_HOOK])) {
                $timestamps[] = $time;
            }
        }
        $this->assertLessThanOrEqual(1, count($timestamps), 'Cron must not be duplicated');
    }

    private function make_user(string $email, string $role): int
    {
        return wp_insert_user([
            'user_login' => substr(md5($email), 0, 8),
            'user_email' => $email,
            'user_pass' => 'x',
            'role' => $role,
        ]);
    }

    private function insert_contribution(int $user_id, string $status, string $title): int
    {
        global $wpdb;
        $goal = $this->create_goal((int) $this->create_community()->id);
        $wpdb->insert(Database::contributions_table(), [
            'goal_id' => $goal->id, 'user_id' => $user_id, 'type' => 'question', 'status' => $status, 'topic' => '', 'title' => $title, 'body' => 'b', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function reset_mailer(): void
    {
        if (function_exists('reset_phpmailer')) {
            reset_phpmailer();
        } else {
            $GLOBALS['phpmailer'] = new \MockPHPMailer(true);
        }
    }

    /**
     * @return array<int, array{to:string, subject:string, body:string}>
     */
    private function sent_mails(): array
    {
        $mailer = $GLOBALS['phpmailer'] ?? null;
        if (!$mailer) {
            return [];
        }
        $sent = [];
        foreach ((array) ($mailer->mock_sent ?? []) as $message) {
            $to = $message['to'] ?? [];
            $to_strings = array_map(static fn($t) => is_array($t) ? ($t[0] ?? '') : (string) $t, (array) $to);
            $sent[] = [
                'to' => implode(',', $to_strings),
                'subject' => (string) ($message['subject'] ?? ''),
                'body' => (string) ($message['body'] ?? ''),
            ];
        }
        return $sent;
    }

    private function ensure_responses_table(): void
    {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', Database::responses_table()));
        if ($exists !== Database::responses_table()) {
            $wpdb->suppress_errors(true);
            \CommonGoals\Database::create_tables();
            $wpdb->suppress_errors(false);
        }
    }
}
