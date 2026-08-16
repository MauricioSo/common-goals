<?php
/**
 * Unit tests for the ContextBuilder (retrieval, ranking, packing).
 *
 * @package CommonGoals\Tests\Unit\AI
 */

namespace CommonGoals\Tests\Unit\AI;

use Brain\Monkey\Functions;
use CommonGoals\AI\ContextBuilder;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class ContextBuilderUnitTest extends UnitTestCase
{
    public function test_excerpt_truncates_and_collapses_whitespace(): void
    {
        $this->assertSame('Hello…', ContextBuilder::excerpt('Hello world this is long', 5));
        $this->assertSame('one two', ContextBuilder::excerpt("one\n\ntwo", 100));
    }

    public function test_excerpt_strips_html_tags(): void
    {
        $this->assertSame('Hello there', ContextBuilder::excerpt('<p>Hello <b>there</b></p>', 100));
    }

    public function test_render_thread_text_truncates_when_too_long(): void
    {
        $long = str_repeat('x', ContextBuilder::MAX_THREAD_CHARS + 50);
        $thread = [
            'contribution' => ['title' => 'T', 'body' => $long, 'topic' => 'x'],
            'responses'    => [],
        ];

        $rendered = ContextBuilder::render_thread_text($thread);

        $this->assertStringContainsString('[truncated]', $rendered);
        $this->assertLessThanOrEqual(ContextBuilder::MAX_THREAD_CHARS + 50, mb_strlen($rendered));
    }

    public function test_render_thread_text_returns_empty_when_contribution_missing(): void
    {
        $this->assertSame('', ContextBuilder::render_thread_text(['contribution' => null, 'responses' => []]));
    }

    public function test_find_related_ranks_by_term_overlap(): void
    {
        Functions\when('home_url')->alias(static fn($p = '') => 'https://example.test' . $p);

        $this->wpdb->queue_get_results([
            (object) ['id' => 1, 'title' => 'Tomato leaf problems', 'body' => 'yellow spots after rain', 'type' => 'question', 'topic' => 'tomate'],
            (object) ['id' => 2, 'title' => 'Unrelated topic', 'body' => 'watering schedule', 'type' => 'question', 'topic' => 'riego'],
        ]);

        $items = ContextBuilder::find_related('tomato yellow rain', 0, 0, 5);

        $this->assertCount(2, $items);
        $this->assertSame(1, $items[0]['id'], 'The tomato result must rank first');
        $this->assertGreaterThan($items[1]['score'], $items[0]['score']);
    }

    public function test_find_related_returns_empty_for_blank_query(): void
    {
        $this->assertSame([], ContextBuilder::find_related('   ', 0, 0, 5));
    }

    public function test_build_thread_returns_null_contribution_when_missing(): void
    {
        Functions\when('apply_filters')->returnArg(2);
        $this->wpdb->queue_get_row(null);

        $thread = ContextBuilder::build_thread(99);

        $this->assertNull($thread['contribution']);
        $this->assertSame([], $thread['responses']);
    }
}
