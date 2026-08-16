<?php
/**
 * Moderate flow: prioritize a pending queue and surface risk signals.
 *
 * @package CommonGoals\AI\Flow
 */

namespace CommonGoals\AI\Flow;

use CommonGoals\Database;
use CommonGoals\Domain;
use CommonGoals\AI\ContextBuilder;
use CommonGoals\AI\OutputValidator;
use CommonGoals\AI\Prompts;
use CommonGoals\AI\CompletionResult;

if (! defined('ABSPATH')) {
    exit;
}

final class ModerateFlow extends AbstractFlow
{
    public string $flow        = 'moderate';
    public bool $requires_login = true;
    public string $capability  = 'moderate';

    protected function build_prompt(array $input): ?string
    {
        $community_id = (int) ($input['community_id'] ?? 0);
        $rows         = $this->fetch_pending($community_id, 24);

        if ($rows === []) {
            $this->empty_queue = true;
            return null;
        }

        $this->fetched_ids = array_map('intval', array_column($rows, 'id'));

        $queue = "QUEUE:\n";
        foreach ($rows as $row) {
            $queue .= sprintf(
                "id=%d | type=%s | title=%s | body=%s\n",
                (int) $row->id,
                (string) $row->type,
                (string) $row->title,
                ContextBuilder::excerpt((string) $row->body, 300)
            );
        }

        return Prompts::moderate($queue);
    }

    /** @var int[] */
    private array $fetched_ids = [];
    private bool $empty_queue  = false;

    /**
     * @return array<int, object>
     */
    private function fetch_pending(int $community_id, int $limit): array
    {
        global $wpdb;

        $contributions = Database::contributions_table();
        $goals         = Database::goals_table();
        $limit         = max(1, min(40, $limit));

        if ($community_id > 0) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT contributions.id, contributions.type, contributions.title, contributions.body
                    FROM {$contributions} contributions
                    LEFT JOIN {$goals} goals ON goals.id = contributions.goal_id
                    WHERE contributions.status = 'pending' AND goals.community_id = %d
                    ORDER BY contributions.created_at ASC
                    LIMIT %d",
                    $community_id,
                    $limit
                )
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, type, title, body FROM {$contributions} WHERE status = 'pending' ORDER BY created_at ASC LIMIT %d",
                $limit
            )
        );
    }

    protected function shape_output(CompletionResult $result): array
    {
        $shaped = OutputValidator::shape($result->content, [
            'priorities' => ['type' => 'list', 'max' => 24, 'default' => []],
            'notes'      => ['type' => 'string', 'max' => 400, 'default' => ''],
        ]);

        $priorities = [];
        foreach ($shaped['priorities'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $id = (int) ($entry['id'] ?? 0);
            if (! in_array($id, $this->fetched_ids, true)) {
                continue;
            }
            $priority = (string) ($entry['priority'] ?? 'normal');
            if (! in_array($priority, ['high', 'normal', 'low'], true)) {
                $priority = 'normal';
            }
            $signals = [];
            foreach (($entry['signals'] ?? []) as $signal) {
                if (is_string($signal) || is_numeric($signal)) {
                    $signals[] = mb_substr((string) $signal, 0, 160);
                }
                if (count($signals) >= 6) {
                    break;
                }
            }
            $priorities[] = [
                'id'       => $id,
                'priority' => $priority,
                'signals'  => $signals,
            ];
        }

        return [
            'priorities' => $priorities,
            'notes'      => $shaped['notes'],
        ];
    }
}
