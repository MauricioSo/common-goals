<?php
/**
 * Compose flow: improve a rough draft into a clear contribution.
 *
 * @package CommonGoals\AI\Flow
 */

namespace CommonGoals\AI\Flow;

use CommonGoals\AI\OutputValidator;
use CommonGoals\AI\Prompts;
use CommonGoals\AI\CompletionResult;
use CommonGoals\Domain;

if (! defined('ABSPATH')) {
    exit;
}

final class ComposeFlow extends AbstractFlow
{
    public string $flow         = 'compose';
    public bool $requires_login = false;

    protected function build_prompt(array $input): ?string
    {
        $draft = trim((string) ($input['draft'] ?? ''));

        if (mb_strlen($draft) < 10) {
            return null;
        }

        $goal          = isset($input['goal_id']) ? Domain::get_active_goal((int) $input['goal_id']) : null;
        $allowed_types = $goal ? Domain::allowed_types_for_goal($goal) : Domain::CONTRIBUTION_TYPES;

        return Prompts::compose($draft, implode(', ', $allowed_types));
    }

    protected function shape_output(CompletionResult $result): array
    {
        $shaped = OutputValidator::shape($result->content, [
            'title'             => ['type' => 'string', 'max' => Domain::MAX_TITLE_LENGTH, 'default' => ''],
            'body'              => ['type' => 'string', 'max' => 4000, 'default' => ''],
            'type'              => ['type' => 'string', 'max' => 40, 'default' => 'question'],
            'topic'             => ['type' => 'string', 'max' => Domain::MAX_TOPIC_LENGTH, 'default' => ''],
            'summary_of_changes'=> ['type' => 'string', 'max' => 280, 'default' => ''],
        ]);

        if (! in_array($shaped['type'], Domain::CONTRIBUTION_TYPES, true)) {
            $shaped['type'] = 'question';
        }

        return [
            'title'              => $shaped['title'],
            'body'               => $shaped['body'],
            'type'               => $shaped['type'],
            'topic'              => $shaped['topic'],
            'summary_of_changes' => $shaped['summary_of_changes'],
        ];
    }
}
