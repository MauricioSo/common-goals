<?php
/**
 * Contract for AI completion clients.
 *
 * @package CommonGoals\AI
 */

namespace CommonGoals\AI;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Implemented by {@see Client} and any test double or alternative provider.
 * Flows depend on this interface so the HTTP transport can be swapped or
 * mocked without touching the flow lifecycle.
 */
interface CompletionClient
{
    /**
     * @param string[]             $system System prompt lines.
     * @param array<int, mixed>    $messages OpenAI-style messages.
     * @param array<string, mixed> $options  Per-call overrides.
     */
    public function complete(array $system, array $messages, array $options = []): CompletionResult;
}
