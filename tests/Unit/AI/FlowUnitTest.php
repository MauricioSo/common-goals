<?php
/**
 * Unit tests for the AI flow lifecycle (gate, prompt, shaping).
 *
 * @package CommonGoals\Tests\Unit\AI
 */

namespace CommonGoals\Tests\Unit\AI;

use Brain\Monkey\Functions;
use CommonGoals\AI\CompletionResult;
use CommonGoals\AI\Flow\ComposeFlow;
use CommonGoals\AI\Flow\GuideFlow;
use CommonGoals\AI\Flow\SummarizeFlow;
use CommonGoals\AI\Settings;
use CommonGoals\Tests\Unit\Support\StubClient;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class FlowUnitTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->configure_enabled_settings();
        Functions\when('apply_filters')->alias(static fn($tag, $value, ...$args) => $value);
    }

    private function configure_enabled_settings(): void
    {
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

        Functions\when('get_transient')->justReturn(false);
        // Monthly spend query returns 0.
        $this->wpdb->queue_get_var(0.0);
    }

    public function test_compose_flow_returns_shaped_draft_on_success(): void
    {
        $this->inject_stub_client(function () {
            return new CompletionResult(
                ok: true,
                content: json_encode([
                    'title' => 'Improved title',
                    'body'  => 'A clearer **body**.',
                    'type'  => 'question',
                    'topic' => 'tomate',
                    'summary_of_changes' => 'Clarified wording',
                ]),
                promptTokens: 10,
                completionTokens: 20,
                model: 'deepseek-v4-flash',
                latencyMs: 150,
                errorCode: '',
                errorMessage: '',
                httpCode: 200
            );
        });

        $flow  = new ComposeFlow();
        $result = $flow->run(['draft' => 'this is my rough draft about tomato leaves']);

        $this->assertTrue($result['ok']);
        $this->assertSame('Improved title', $result['data']['title']);
        $this->assertSame('question', $result['data']['type']);
        $this->assertSame('tomate', $result['data']['topic']);
    }

    public function test_compose_flow_rejects_too_short_draft(): void
    {
        $flow   = new ComposeFlow();
        $result = $flow->run(['draft' => 'short']);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_input', $result['error']['code']);
    }

    public function test_compose_flow_coerces_invalid_type_back_to_question(): void
    {
        $this->inject_stub_client(function () {
            return new CompletionResult(
                ok: true,
                content: json_encode(['title' => 'T', 'body' => 'B', 'type' => 'bogus', 'topic' => '']),
                promptTokens: 1, completionTokens: 1, model: 'm', latencyMs: 1, errorCode: '', errorMessage: '', httpCode: 200
            );
        });

        $result = (new ComposeFlow())->run(['draft' => 'a sufficiently long draft to pass validation']);

        $this->assertTrue($result['ok']);
        $this->assertSame('question', $result['data']['type']);
    }

    public function test_staff_flow_requires_login(): void
    {
        // is_user_logged_in() defaults to false in the test case.
        $result = (new GuideFlow())->run(['contribution_ids' => [1, 2]]);

        $this->assertFalse($result['ok']);
        $this->assertSame('login_required', $result['error']['code']);
    }

    public function test_rate_limited_flow_returns_429_envelope(): void
    {
        Functions\when('get_transient')->justReturn(['count' => 99, 'started' => time()]);

        $result = (new ComposeFlow())->run(['draft' => 'a sufficiently long draft to pass validation']);

        $this->assertFalse($result['ok']);
        $this->assertSame('rate_limited', $result['error']['code']);
    }

    public function test_provider_error_propagates_as_envelope(): void
    {
        $this->inject_stub_client(function () {
            return CompletionResult::error('provider_http', 'Service unavailable', 100, 503);
        });

        $result = (new ComposeFlow())->run(['draft' => 'a sufficiently long draft to pass validation']);

        $this->assertFalse($result['ok']);
        $this->assertSame('provider_http', $result['error']['code']);
        $this->assertSame('Service unavailable', $result['error']['message']);
    }

    public function test_summarize_flow_drops_empty_contribution(): void
    {
        // get_visible_contribution returns null.
        $this->wpdb->queue_get_row(null);

        $result = (new SummarizeFlow())->run(['contribution_id' => 9]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_input', $result['error']['code']);
    }

    /**
     * Registers the StubClient through the common_goals_ai_client filter.
     */
    private function inject_stub_client(callable $factory): void
    {
        $stub = new StubClient();
        $stub->queue($factory());

        Functions\when('apply_filters')->alias(static function ($tag, $value, ...$args) use ($stub) {
            if ($tag === 'common_goals_ai_client') {
                return $stub;
            }
            return $value;
        });
    }
}
