<?php
/**
 * Builds local, permission-scoped context for retrieval-augmented flows.
 *
 * @package CommonGoals\AI
 */

namespace CommonGoals\AI;

use CommonGoals\Database;
use CommonGoals\Domain;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Fetches raw community content (contributions, responses, guides) and packs
 * it into compact text payloads for the model.
 *
 * Retrieval is deliberately conservative: it only reads publicly visible rows
 * scoped to the requested goal/community, applies hard row caps and strips
 * every private column (user_id, alignment_rules, created_by). This is the
 * data-minimization boundary of the integration.
 */
final class ContextBuilder
{
    public const MAX_THREAD_CHARS = 16000;
    public const MAX_SUMMARY_RESPONSES = 60;

    /**
     * Returns candidate contributions whose title/body loosely match a query.
     *
     * Uses MySQL FULLTEXT when available and falls back to LIKE. Results are
     * limited to public statuses within the goal/community scope.
     *
     * @return array<int, array{id: int, title: string, body: string, type: string, topic: string, url: string, score: float}>
     */
    public static function find_related(string $query, int $goal_id, int $community_id, int $limit = 5): array
    {
        global $wpdb;

        if (trim($query) === '' || $limit <= 0) {
            return [];
        }

        $contributions = Database::contributions_table();
        $goals         = Database::goals_table();
        $visible       = Domain::PUBLIC_STATUSES;
        $placeholders  = implode(',', array_fill(0, count($visible), '%s'));
        $limit         = max(1, min(20, $limit));

        $where  = "contributions.status IN ({$placeholders})";
        $params = $visible;

        if ($goal_id > 0) {
            $where  .= ' AND contributions.goal_id = %d';
            $params[] = $goal_id;
        }

        if ($community_id > 0) {
            $where  .= ' AND goals.community_id = %d';
            $params[] = $community_id;
        }

        $like   = '%' . $wpdb->esc_like($query) . '%';
        $where  .= ' AND (contributions.title LIKE %s OR contributions.body LIKE %s)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $limit;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT contributions.id, contributions.title, contributions.body, contributions.type, contributions.topic
                FROM {$contributions} contributions
                LEFT JOIN {$goals} goals ON goals.id = contributions.goal_id
                WHERE {$where}
                ORDER BY contributions.score DESC, contributions.created_at DESC
                LIMIT %d",
                ...$params
            )
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'    => (int) $row->id,
                'title' => (string) $row->title,
                'body'  => self::excerpt((string) $row->body, 400),
                'type'  => (string) $row->type,
                'topic' => (string) $row->topic,
                'url'   => self::contribution_url((int) $row->id),
                'score' => 0.0,
            ];
        }

        return self::rank($query, $out);
    }

    /**
     * Returns a thread (contribution + published responses) packed for the
     * summarizer and answer flows.
     *
     * @return array{contribution: array<string,mixed>|null, responses: array<int, mixed>}
     */
    public static function build_thread(int $contribution_id): array
    {
        global $wpdb;

        $contribution = Domain::get_visible_contribution($contribution_id);

        if (! $contribution) {
            return ['contribution' => null, 'responses' => []];
        }

        $responses_table = Database::responses_table();
        $raw             = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, parent_id, body, score, created_at
                FROM {$responses_table}
                WHERE contribution_id = %d AND status = 'published'
                ORDER BY created_at ASC
                LIMIT %d",
                $contribution_id,
                self::MAX_SUMMARY_RESPONSES
            )
        );

        $packed_contribution = [
            'id'    => (int) $contribution->id,
            'title' => (string) $contribution->title,
            'body'  => self::excerpt((string) $contribution->body, 2000),
            'type'  => (string) $contribution->type,
            'topic' => (string) $contribution->topic,
        ];

        $responses = [];
        foreach ($raw as $index => $row) {
            $responses[] = [
                'n'         => $index + 1,
                'id'        => (int) $row->id,
                'parent_id' => (int) $row->parent_id,
                'body'      => self::excerpt((string) $row->body, 1200),
                'score'     => (int) $row->score,
            ];
        }

        return ['contribution' => $packed_contribution, 'responses' => $responses];
    }

    /**
     * Renders a thread into a compact text transcript for the model.
     *
     * @param array{contribution: array<string,mixed>|null, responses: array<int, mixed>} $thread
     */
    public static function render_thread_text(array $thread): string
    {
        $contribution = $thread['contribution'] ?? null;
        if (! is_array($contribution)) {
            return '';
        }

        $lines   = [];
        $lines[] = 'TITLE: ' . ($contribution['title'] ?? '');
        $lines[] = 'TOPIC: ' . ($contribution['topic'] ?? '');
        $lines[] = 'BODY: ' . ($contribution['body'] ?? '');

        foreach ($thread['responses'] ?? [] as $response) {
            $lines[] = sprintf(
                '#%d (score %d): %s',
                (int) ($response['n'] ?? 0),
                (int) ($response['score'] ?? 0),
                (string) ($response['body'] ?? '')
            );
        }

        $text = implode("\n", $lines);

        if (mb_strlen($text) > self::MAX_THREAD_CHARS) {
            $text = mb_substr($text, 0, self::MAX_THREAD_CHARS) . "\n[truncated]";
        }

        return $text;
    }

    /**
     * Returns a short excerpt trimmed to the given length, with HTML stripped.
     */
    public static function excerpt(string $text, int $max = 400): string
    {
        $text = wp_strip_all_tags($text);
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max)) . '…';
    }

    /**
     * Builds a contribution permalink consistent with the frontend router.
     */
    public static function contribution_url(int $contribution_id): string
    {
        return home_url('/?cg_thread=' . $contribution_id);
    }

    /**
     * Lightweight relevance ranking that rewards term overlap so the model
     * receives the most on-topic candidates first.
     *
     * @param array<int, array{id: int, title: string, body: string, type: string, topic: string, url: string, score: float}> $items
     * @return array<int, array{id: int, title: string, body: string, type: string, topic: string, url: string, score: float}>
     */
    private static function rank(string $query, array $items): array
    {
        $terms = array_values(array_filter(array_map('strtolower', preg_split('/\s+/', $query) ?: []), 'strlen'));
        $terms = array_slice($terms, 0, 12);

        foreach ($items as &$item) {
            $haystack = strtolower($item['title'] . ' ' . $item['body'] . ' ' . $item['topic']);
            $hits     = 0;
            foreach ($terms as $term) {
                if ($term !== '' && str_contains($haystack, $term)) {
                    $hits++;
                }
            }
            $item['score'] = $terms === [] ? 0.0 : round($hits / count($terms), 2);
        }
        unset($item);

        usort($items, static fn($a, $b) => $b['score'] <=> $a['score']);

        return $items;
    }
}
