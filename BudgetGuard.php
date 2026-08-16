<?php
/**
 * Budget enforcement and run auditing for AI calls.
 *
 * @package CommonGoals\AI
 */

namespace CommonGoals\AI;

use CommonGoals\Database;
use CommonGoals\EventLogger;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Guards every flow with two independent controls:
 *
 * 1. A short per-user rate limit (per action, per 5-minute window) reused
 *    from the Domain layer so the AI cannot be abused like a free endpoint.
 * 2. A monthly USD budget tracked in the cg_ai_runs table; once the configured
 *    monthly budget is consumed, flows degrade gracefully instead of billing.
 *
 * Every call (success or failure) is recorded in cg_ai_runs for observability
 * and exported alongside the existing audit log. No prompt text, key or
 * personal data is stored — only flow name, model, tokens, cost and status.
 */
final class BudgetGuard
{
    public const RATE_LIMIT_PER_WINDOW = 8;
    public const STATUS_OK    = 'success';
    public const STATUS_ERROR = 'error';
    public const STATUS_REJECTED = 'rejected';

    /**
     * Returns true when the current user may invoke the given flow now.
     *
     * Checks (in order): feature enabled, configured, content-sharing consent,
     * per-user rate limit and remaining monthly budget.
     */
    public static function can_run(string $flow): array
    {
        if (! Settings::is_flow_enabled($flow)) {
            return self::deny('disabled', __('This assistant flow is not enabled.', 'common-goals'));
        }

        if (! Settings::is_configured()) {
            return self::deny('not_configured', __('The assistant is not configured.', 'common-goals'));
        }

        if (! Settings::share_content()) {
            return self::deny('no_consent', __('Sending community content to the assistant is disabled.', 'common-goals'));
        }

        $key = 'ai_' . $flow;
        if (! self::check_rate_limit($key)) {
            return self::deny('rate_limited', __('You are using the assistant too quickly. Please wait a few minutes.', 'common-goals'));
        }

        if (self::monthly_spend() >= Settings::monthly_budget()) {
            return self::deny('budget_exceeded', __('The assistant monthly budget has been reached.', 'common-goals'));
        }

        return ['allowed' => true];
    }

    /**
     * Records the outcome of a run and consumes the rate-limit slot on success.
     */
    public static function record(string $flow, CompletionResult $result): void
    {
        $cost   = OutputValidator::estimate_cost($result);
        $status = $result->ok ? self::STATUS_OK : self::STATUS_ERROR;

        self::insert_run([
            'flow'              => $flow,
            'model'             => $result->model,
            'prompt_tokens'     => $result->promptTokens,
            'completion_tokens' => $result->completionTokens,
            'cost_usd'          => $cost,
            'status'            => $status,
            'error_code'        => $result->errorCode,
            'latency_ms'        => $result->latencyMs,
        ]);

        EventLogger::log('ai', 0, 'ai.' . $flow, [
            'model'        => $result->model,
            'status'       => $status,
            'tokens'       => $result->totalTokens(),
            'cost_usd'     => $cost,
            'latency_ms'   => $result->latencyMs,
            'error_code'   => $result->errorCode,
        ]);

        if ($result->ok) {
            self::bump_rate_limit('ai_' . $flow);
        }
    }

    /**
     * Returns the total USD spent in the current calendar month.
     */
    public static function monthly_spend(): float
    {
        global $wpdb;

        $table = Database::ai_runs_table();
        $start = gmdate('Y-m-01 00:00:00');

        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(cost_usd), 0) FROM {$table} WHERE created_at >= %s",
            $start
        ));
    }

    /**
     * Returns the spend grouped by flow for a dashboard widget.
     *
     * @return array<string, array{calls: int, cost: float}>
     */
    public static function monthly_breakdown(): array
    {
        global $wpdb;

        $table = Database::ai_runs_table();
        $start = gmdate('Y-m-01 00:00:00');
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT flow, COUNT(*) AS calls, COALESCE(SUM(cost_usd), 0) AS cost
            FROM {$table}
            WHERE created_at >= %s
            GROUP BY flow
            ORDER BY cost DESC",
            $start
        ));

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->flow] = ['calls' => (int) $row->calls, 'cost' => (float) $row->cost];
        }

        return $out;
    }

    /**
     * Rate-limit check mirroring Domain::check_rate_limit but isolated under
     * an `ai_` prefix so AI usage does not consume the contribution quota.
     *
     * Returns true when the user is within the limit.
     */
    public static function check_rate_limit(string $action): bool
    {
        $key       = 'cg_rate_' . $action . '_' . self::submitter_identifier();
        $window    = 300;
        $max       = self::RATE_LIMIT_PER_WINDOW;
        $counts    = get_transient($key);
        $counts    = is_array($counts) ? $counts : [];

        if (isset($counts['count']) && (int) $counts['count'] >= $max) {
            return false;
        }

        return true;
    }

    /**
     * Increments the rate-limit counter after a successful call.
     */
    public static function bump_rate_limit(string $action): void
    {
        $key    = 'cg_rate_' . $action . '_' . self::submitter_identifier();
        $window = 300;
        $counts = get_transient($key);
        $counts = is_array($counts) ? $counts : ['count' => 0, 'started' => time()];
        $count  = (int) ($counts['count'] ?? 0) + 1;
        $started = (int) ($counts['started'] ?? time());

        set_transient($key, ['count' => $count, 'started' => $started], $window);
    }

    /**
     * Returns the identifier used to scope rate limits (user id or guest hash).
     */
    public static function submitter_identifier(): string
    {
        $user_id = get_current_user_id();

        if ($user_id > 0) {
            return 'u' . $user_id;
        }

        return 'g0';
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function insert_run(array $data): void
    {
        global $wpdb;

        $wpdb->insert(
            Database::ai_runs_table(),
            [
                'flow'              => (string) $data['flow'],
                'user_id'           => get_current_user_id(),
                'model'             => (string) $data['model'],
                'prompt_tokens'     => (int) $data['prompt_tokens'],
                'completion_tokens' => (int) $data['completion_tokens'],
                'cost_usd'          => (float) $data['cost_usd'],
                'status'            => (string) $data['status'],
                'error_code'        => (string) ($data['error_code'] ?? ''),
                'latency_ms'        => (int) ($data['latency_ms'] ?? 0),
                'created_at'        => current_time('mysql'),
            ],
            ['%s', '%d', '%s', '%d', '%d', '%f', '%s', '%s', '%d', '%s']
        );
    }

    /**
     * @return array{allowed: false, code: string, message: string}
     */
    private static function deny(string $code, string $message): array
    {
        return ['allowed' => false, 'code' => $code, 'message' => $message];
    }
}
