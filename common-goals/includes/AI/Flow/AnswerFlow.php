<?php
/**
 * Answer flow: draft a grounded response with community citations.
 *
 * @package CommonGoals\AI\Flow
 */

namespace CommonGoals\AI\Flow;

use CommonGoals\AI\ContextBuilder;
use CommonGoals\AI\OutputValidator;
use CommonGoals\AI\Prompts;
use CommonGoals\AI\CompletionResult;
use CommonGoals\Domain;

if (! defined('ABSPATH')) {
    exit;
}

final class AnswerFlow extends AbstractFlow
{
    public string $flow         = 'answer';
    public bool $requires_login = true;

    protected function build_prompt(array $input): ?string
    {
        $contribution_id = (int) ($input['contribution_id'] ?? 0);
        $thread          = ContextBuilder::build_thread($contribution_id);

        if (! is_array($thread['contribution'])) {
            return null;
        }

        $contribution = $thread['contribution'];
        $question     = "TITLE: {$contribution['title']}\nBODY: {$contribution['body']}";

        $related = ContextBuilder::find_related(
            (string) $contribution['title'],
            0,
            (int) ($input['community_id'] ?? 0),
            4
        );

        $context = '';
        foreach ($related as $item) {
            $context .= "id={$item['id']} | title={$item['title']} | body={$item['body']}\n";
        }

        if ($context === '') {
            $context = 'No related community sources were found.';
        }

        $this->related_ids = array_column($related, 'id');

        return Prompts::answer($question, $context);
    }

    /** @var int[] */
    private array $related_ids = [];

    protected function shape_output(CompletionResult $result): array
    {
        $shaped = OutputValidator::shape($result->content, [
            'draft'       => ['type' => 'string', 'max' => 4000, 'default' => ''],
            'citations'   => ['type' => 'list', 'max' => 4, 'default' => []],
            'missing_info'=> ['type' => 'string', 'max' => 280, 'default' => ''],
        ]);

        $valid = [];
        foreach ($shaped['citations'] as $citation) {
            if (! is_array($citation)) {
                continue;
            }
            $id = (int) ($citation['id'] ?? 0);
            if ($id <= 0 || ! in_array($id, $this->related_ids, true)) {
                continue; // drop citations that do not map to real sources
            }
            $valid[] = [
                'id'    => $id,
                'quote' => mb_substr((string) ($citation['quote'] ?? ''), 0, 300),
                'url'   => ContextBuilder::contribution_url($id),
            ];
        }

        return [
            'draft'        => $shaped['draft'],
            'citations'    => $valid,
            'missing_info' => $shaped['missing_info'],
        ];
    }
}
