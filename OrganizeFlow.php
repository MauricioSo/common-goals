<?php
/**
 * Organize flow: propose tags, relations and duplicate candidates.
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

final class OrganizeFlow extends AbstractFlow
{
    public string $flow        = 'organize';
    public bool $requires_login = true;
    public string $capability  = 'moderate';

    protected function build_prompt(array $input): ?string
    {
        $community_id = (int) ($input['community_id'] ?? 0);
        $ids          = $this->normalize_ids($input['contribution_ids'] ?? []);

        if ($ids === []) {
            return null;
        }

        $rows = $this->fetch($ids, $community_id);

        if ($rows === []) {
            return null;
        }

        $this->fetched_ids = array_column($rows, 'id');

        $candidates = "CANDIDATES:\n";
        foreach ($rows as $row) {
            $candidates .= "id={$row->id} | title={$row->title} | topic={$row->topic} | body=" . ContextBuilder::excerpt((string) $row->body, 300) . "\n";
        }

        return Prompts::organize($candidates);
    }

    /** @var int[] */
    private array $fetched_ids = [];

    /**
     * @param int[] $ids
     * @return array<int, object>
     */
    private function fetch(array $ids, int $community_id): array
    {
        global $wpdb;

        $contributions = Database::contributions_table();
        $goals         = Database::goals_table();
        $placeholders  = implode(',', array_fill(0, count($ids), '%d'));
        $params        = $ids;

        if ($community_id > 0) {
            $params[] = $community_id;
            $sql = "SELECT contributions.id, contributions.title, contributions.body, contributions.topic
                    FROM {$contributions} contributions
                    LEFT JOIN {$goals} goals ON goals.id = contributions.goal_id
                    WHERE contributions.id IN ({$placeholders}) AND goals.community_id = %d";
        } else {
            $sql = "SELECT id, title, body, topic FROM {$contributions} contributions WHERE id IN ({$placeholders})";
        }

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    protected function shape_output(CompletionResult $result): array
    {
        $shaped = OutputValidator::shape($result->content, [
            'topic'            => ['type' => 'string', 'max' => 120, 'default' => ''],
            'relations'        => ['type' => 'list', 'max' => 12, 'default' => []],
            'duplicates'       => ['type' => 'list', 'max' => 6, 'default' => []],
            'rationale'        => ['type' => 'string', 'max' => 400, 'default' => ''],
            'merge_recommended'=> ['type' => 'bool', 'default' => false],
        ]);

        $valid_ids = array_map('intval', $this->fetched_ids);

        $relations = [];
        foreach ($shaped['relations'] as $relation) {
            if (! is_array($relation)) {
                continue;
            }
            $id = (int) ($relation['id'] ?? 0);
            if (! in_array($id, $valid_ids, true)) {
                continue;
            }
            $relations[] = [
                'id'       => $id,
                'relation' => mb_substr((string) ($relation['relation'] ?? ''), 0, 60),
            ];
        }

        $duplicates = [];
        foreach ($shaped['duplicates'] as $group) {
            if (! is_array($group)) {
                continue;
            }
            $clean = [];
            foreach ($group as $value) {
                $id = (int) ($value);
                if (in_array($id, $valid_ids, true)) {
                    $clean[] = $id;
                }
            }
            if (count($clean) >= 2) {
                $duplicates[] = array_values(array_unique($clean));
            }
        }

        return [
            'topic'             => $shaped['topic'],
            'relations'         => $relations,
            'duplicates'        => $duplicates,
            'rationale'         => $shaped['rationale'],
            'merge_recommended' => (bool) $shaped['merge_recommended'],
        ];
    }

    /**
     * @param mixed $raw
     * @return int[]
     */
    private function normalize_ids($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach (array_slice($raw, 0, 20) as $value) {
            $id = absint($value);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
