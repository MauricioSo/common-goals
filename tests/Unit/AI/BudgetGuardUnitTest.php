<?php
/**
 * Unit tests for the BudgetGuard (rate limit, budget, auditing).
 *
 * @package CommonGoals\Tests\Unit\AI
 */

namespace CommonGoals\Tests\Unit\AI;

use Brain\Monkey\Functions;
use CommonGoals\AI\BudgetGuard;
use CommonGoals\AI\CompletionResult;
use CommonGoals\AI\Settings;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class BudgetGuardUnitTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_option')->alias(static function ($name, $default = false) {
            if ($name === Settings::OPTION_NAME) {
                return array_merge(Settings::defaults(), [
                    'api_key'       => 'fixture-key',
                    'share_content' => true,
                    'enabled_flows' => array_fill_keys(Settings::flow_ids(), true),
                ]);
            }
            return $default;
        });

        Functions\when('apply_filters')->returnArg(2);
    }

    public function test_can_run_allows_when_everything_is_configured(): void
    {
        Functions\when('get_transient')->justReturn(false);

        $gate = BudgetGuard::can_run('discover');

        $this->assertTrue($gate['allowed']);
    }

    public function test_can_run_denies_when_rate_limit_exceeded(): void
    {
        Functions\when('get_transient')->justReturn(['count' => 99, 'started' => time()]);

        $gate = BudgetGuard::can_run('compose');

        $this->assertFalse($gate['allowed']);
        $this->assertSame('rate_limited', $gate['code']);
    }

    public function test_can_run_denies_when_monthly_budget_exceeded(): void
    {
        Functions\when('get_transient')->justReturn(false);
        $this->wpdb->queue_get_var(999.0);

        $gate = BudgetGuard::can_run('answer');

        $this->assertFalse($gate['allowed']);
        $this->assertSame('budget_exceeded', $gate['code']);
    }

    public function test_can_run_denies_when_flow_disabled(): void
    {
        Functions\when('get_option')->alias(static function ($name, $default = false) {
            if ($name === Settings::OPTION_NAME) {
                $settings = Settings::defaults();
            $settings['api_key'] = 'fixture-key';
                $settings['enabled_flows']['discover'] = false;
                return $settings;
            }
            return $default;
        });
        Functions\when('get_transient')->justReturn(false);

        $gate = BudgetGuard::can_run('discover');

        $this->assertFalse($gate['allowed']);
        $this->assertSame('disabled', $gate['code']);
    }

    public function test_record_logs_run_row_with_cost_and_status(): void
    {
        $result = new CompletionResult(
            ok: true,
            content: '{}',
            promptTokens: 100,
            completionTokens: 50,
            model: 'deepseek-v4-flash',
            latencyMs: 320,
            errorCode: '',
            errorMessage: '',
            httpCode: 200
        );

        BudgetGuard::record('discover', $result);

        $insert = null;
        foreach ($this->wpdb->calls as $call) {
            if ($call['method'] === 'insert' && str_contains((string) $call['sql'], 'cg_ai_runs')) {
                $insert = $call;
                break;
            }
        }

        $this->assertNotNull($insert, 'A run row must be inserted into cg_ai_runs.');
        $this->assertSame('success', $insert['extra']['data']['status']);
        $this->assertSame('discover', $insert['extra']['data']['flow']);
        $this->assertSame(100, $insert['extra']['data']['prompt_tokens']);
        $this->assertSame(50, $insert['extra']['data']['completion_tokens']);
    }

    public function test_record_marks_errors_as_error_status(): void
    {
        $result = CompletionResult::error('provider_http', 'boom', 100, 500);

        BudgetGuard::record('answer', $result);

        $insert = null;
        foreach ($this->wpdb->calls as $call) {
            if ($call['method'] === 'insert' && str_contains((string) $call['sql'], 'cg_ai_runs')) {
                $insert = $call;
                break;
            }
        }

        $this->assertNotNull($insert);
        $this->assertSame('error', $insert['extra']['data']['status']);
        $this->assertSame('provider_http', $insert['extra']['data']['error_code']);
    }
}
