<?php
/**
 * AI client test double that returns scripted results.
 *
 * @package CommonGoals\Tests\Unit\Support
 */

namespace CommonGoals\Tests\Unit\Support;

use CommonGoals\AI\CompletionClient;
use CommonGoals\AI\CompletionResult;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Replaces the real HTTP client in unit tests. Tests push CompletionResult
 * instances onto the queue and the stub shifts one per call. Implements the
 * {@see CompletionClient} contract so it can be injected via the
 * common_goals_ai_client filter without extending the final Client.
 */
final class StubClient implements CompletionClient
{
    /** @var array<int, CompletionResult> */
    private array $queue = [];

    /** @var array<int, array{system: string[], messages: array<int,mixed>, options: array<string,mixed>}> */
    public array $calls = [];

    public function queue(CompletionResult $result): void
    {
        $this->queue[] = $result;
    }

    public function complete(array $system, array $messages, array $options = []): CompletionResult
    {
        $this->calls[] = ['system' => $system, 'messages' => $messages, 'options' => $options];

        return array_shift($this->queue) ?? CompletionResult::error('empty_queue', 'No queued result.');
    }
}
