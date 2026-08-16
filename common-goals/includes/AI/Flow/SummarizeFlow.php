<?php
/**
 * Summarize flow: layered summary of a long thread.
 *
 * @package CommonGoals\AI\Flow
 */

namespace CommonGoals\AI\Flow;

use CommonGoals\AI\ContextBuilder;
use CommonGoals\AI\OutputValidator;
use CommonGoals\AI\Prompts;
use CommonGoals\AI\CompletionResult;

if (! defined('ABSPATH')) {
    exit;
}

final class SummarizeFlow extends AbstractFlow
{
    public string $flow         = 'summarize';
    public bool $requires_login = false;

    protected function build_prompt(array $input): ?string
    {
        $contribution_id = (int) ($input['contribution_id'] ?? 0);
        $thread          = ContextBuilder::build_thread($contribution_id);

        if (! is_array($thread['contribution'])) {
            return null;
        }

        $text = ContextBuilder::render_thread_text($thread);

        if (trim($text) === '') {
            return null;
        }

        $this->response_count = count($thread['responses']);

        return Prompts::summarize($text);
    }

    private int $response_count = 0;

    protected function shape_output(CompletionResult $result): array
    {
        $shaped = OutputValidator::shape($result->content, [
            'agreements'    => ['type' => 'list_of_strings', 'max' => 5, 'default' => []],
            'open_points'   => ['type' => 'list_of_strings', 'max' => 5, 'default' => []],
            'disagreements' => ['type' => 'list_of_strings', 'max' => 5, 'default' => []],
            'next_steps'    => ['type' => 'list_of_strings', 'max' => 5, 'default' => []],
            'cutoff_after'  => ['type' => 'int', 'default' => 0],
        ]);

        return [
            'agreements'     => $shaped['agreements'],
            'open_points'    => $shaped['open_points'],
            'disagreements'  => $shaped['disagreements'],
            'next_steps'     => $shaped['next_steps'],
            'cutoff_after'   => $shaped['cutoff_after'],
            'total_responses' => $this->response_count,
        ];
    }
}
