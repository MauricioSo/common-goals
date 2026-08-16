<?php
/**
 * Discover flow: find related content before a duplicate is posted.
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

final class DiscoverFlow extends AbstractFlow
{
    public string $flow         = 'discover';
    public bool $requires_login = false;

    protected function build_prompt(array $input): ?string
    {
        $query       = trim((string) ($input['query'] ?? ''));
        $goal_id     = (int) ($input['goal_id'] ?? 0);
        $community_id = (int) ($input['community_id'] ?? 0);

        if ($query === '') {
            return null;
        }

        $related = ContextBuilder::find_related($query, $goal_id, $community_id, 6);

        if ($related === []) {
            $context = 'No existing public contributions matched this intent.';
        } else {
            $context = "EXISTING CONTRIBUTIONS:\n";
            foreach ($related as $item) {
                $context .= "id={$item['id']} | title={$item['title']} | body={$item['body']}\n";
            }
        }

        $this->context_items = $related;

        return Prompts::discover($query, $context);
    }

    /**
     * @var array<int, array{id: int, title: string, body: string, url: string}>
     */
    private array $context_items = [];

    protected function shape_output(CompletionResult $result): array
    {
        $shaped = OutputValidator::shape($result->content, [
            'related'    => ['type' => 'list', 'max' => 3, 'default' => []],
            'suggestion' => ['type' => 'string', 'max' => 280, 'default' => ''],
        ]);

        $by_id = [];
        foreach ($this->context_items as $item) {
            $by_id[(int) $item['id']] = $item;
        }

        $enriched = [];
        foreach ($shaped['related'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $id = (int) ($entry['id'] ?? 0);
            if (! isset($by_id[$id])) {
                continue; // drop fabricated references
            }
            $enriched[] = [
                'id'        => $id,
                'title'     => $by_id[$id]['title'],
                'url'       => $by_id[$id]['url'],
                'reason'    => mb_substr((string) ($entry['reason'] ?? ''), 0, 280),
                'confidence' => (float) ($entry['confidence'] ?? 0),
            ];
        }

        return [
            'related'    => $enriched,
            'suggestion' => $shaped['suggestion'],
        ];
    }
}
