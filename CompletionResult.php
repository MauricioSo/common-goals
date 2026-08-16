<?php
/**
 * Immutable, normalized result of an AI completion request.
 *
 * @package CommonGoals\AI
 */

namespace CommonGoals\AI;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Carries either a successful completion or a structured error so flows can
 * branch without catching exceptions, and BudgetGuard can log a single row
 * regardless of outcome.
 */
final class CompletionResult
{
    /**
     * @param string $content           Raw model text output.
     * @param int    $promptTokens      Tokens reported as consumed by the prompt.
     * @param int    $completionTokens  Tokens reported as generated.
     * @param string $model             Effective model identifier.
     * @param int    $latencyMs         Wall-clock latency in milliseconds.
     * @param string $errorCode         Short machine code on failure ('' on success).
     * @param string $errorMessage      Human-readable error detail.
     * @param int    $httpCode          HTTP status returned by the provider.
     */
    public function __construct(
        public bool $ok,
        public string $content,
        public int $promptTokens,
        public int $completionTokens,
        public string $model,
        public int $latencyMs,
        public string $errorCode,
        public string $errorMessage,
        public int $httpCode
    ) {
    }

    /**
     * Builds an error result.
     */
    public static function error(string $code, string $message, int $latencyMs = 0, int $httpCode = 0): self
    {
        return new self(
            ok: false,
            content: '',
            promptTokens: 0,
            completionTokens: 0,
            model: Settings::model(),
            latencyMs: $latencyMs,
            errorCode: $code,
            errorMessage: $message,
            httpCode: $httpCode
        );
    }

    /**
     * Total tokens consumed by the call (used for budget estimates).
     */
    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
