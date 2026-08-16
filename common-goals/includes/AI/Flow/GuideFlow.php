<?php
/**
 * Guide flow: synthesize a living guide draft from selected contributions.
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

final class GuideFlow extends AbstractFlow
{
    public string $flow        = 'guide';
    public bool $requires_login = true;
    public string $capability  = 'publish_guides';

    protected function build_prompt(array $input): ?string
    {
        $ids = $this->normalize_ids($input['contribution_ids'] ?? []);

        if ($ids === []) {
            return null;
        }

        $rows = $this->fetch($ids);

        if ($rows === []) {
            return null;
        }

        $this->fetched_ids = array_column($rows, 'id');

        $sources = "SOURCES:\n";
        foreach ($rows as $row) {
            $sources .= "id={$row->id} | title={$row->title} | body=" . ContextBuilder::excerpt((string) $row->body, 800) . "\n";
        }

        return Prompts::guide($sources);
    }

    /** @var int[] */
    private array $fetched_ids = [];

    /**
     * @param int[] $ids
     * @return array<int, object>
     */
    private function fetch(array $ids): array
    {
        global $wpdb;

        $contributions = Database::contributions_table();
        $placeholders  = implode(',', array_fill(0, count($ids), '%d'));
        $visible       = Domain::PUBLIC_STATUSES;
        $status_placeholders = implode(',', array_fill(0, count($visible), '%s'));
        $params        = array_merge($ids, $visible);

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, title, body FROM {$contributions} WHERE id IN ({$placeholders}) AND status IN ({$status_placeholders})",
                ...$params
            )
        );
    }

    protected function shape_output(CompletionResult $result): array
    {
        $shaped = OutputValidator::shape($result->content, [
            'title'      => ['type' => 'string', 'max' => Domain::MAX_TITLE_LENGTH, 'default' => ''],
            'sections'   => ['type' => 'list', 'max' => 6, 'default' => []],
            'unresolved' => ['type' => 'list_of_strings', 'max' => 5, 'default' => []],
            'update_hint'=> ['type' => 'string', 'max' => 280, 'default' => ''],
        ]);

        $valid = array_map('intval', $this->fetched_ids);

        $sections = [];
        foreach ($shaped['sections'] as $section) {
            if (! is_array($section)) {
                continue;
            }
            $sources = [];
            foreach (($section['sources'] ?? []) as $sid) {
                $id = (int) $sid;
                if (in_array($id, $valid, true)) {
                    $sources[] = $id;
                }
            }
            if ($sources === []) {
                continue; // a section without grounded sources is dropped
            }
            $sections[] = [
                'heading' => mb_substr((string) ($section['heading'] ?? ''), 0, 190),
                'body'    => mb_substr((string) ($section['body'] ?? ''), 0, 800),
                'sources' => array_values(array_unique($sources)),
            ];
        }

        return [
            'title'      => $shaped['title'],
            'sections'   => $sections,
            'unresolved' => $shaped['unresolved'],
            'update_hint'=> $shaped['update_hint'],
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
