<?php
/**
 * Unit tests for the Prompts templates.
 *
 * @package CommonGoals\Tests\Unit\AI
 */

namespace CommonGoals\Tests\Unit\AI;

use CommonGoals\AI\Prompts;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class PromptsUnitTest extends UnitTestCase
{
    public function test_system_prompt_enforces_human_in_the_loop_and_json(): void
    {
        $system = implode("\n", Prompts::system());

        $this->assertStringContainsString('never publish', $system);
        $this->assertStringContainsString('never invent', $system);
        $this->assertStringContainsString('valid JSON', $system);
    }

    public function test_discover_prompt_includes_intent_context_and_json_shape(): void
    {
        $prompt = Prompts::discover('my question', 'CONTEXT-HERE');

        $this->assertStringContainsString('my question', $prompt);
        $this->assertStringContainsString('CONTEXT-HERE', $prompt);
        $this->assertStringContainsString('"related"', $prompt);
        $this->assertStringContainsString('"suggestion"', $prompt);
    }

    public function test_compose_prompt_restricts_to_allowed_types(): void
    {
        $prompt = Prompts::compose('draft', 'question, problem');

        $this->assertStringContainsString('ALLOWED CONTRIBUTION TYPES: question, problem', $prompt);
    }

    public function test_answer_prompt_requires_citations_grounding(): void
    {
        $prompt = Prompts::answer('Q', 'C');

        $this->assertStringContainsString('"citations"', $prompt);
        $this->assertStringContainsString('reference ids present', $prompt);
    }

    public function test_summarize_prompt_emits_layered_shape_with_cutoff(): void
    {
        $prompt = Prompts::summarize('THREAD');

        $this->assertStringContainsString('"agreements"', $prompt);
        $this->assertStringContainsString('"disagreements"', $prompt);
        $this->assertStringContainsString('"cutoff_after"', $prompt);
    }

    public function test_guide_prompt_forbids_invented_consensus(): void
    {
        $prompt = Prompts::guide('SOURCES');

        $this->assertStringContainsString('never invent consensus', $prompt);
        $this->assertStringContainsString('"sources"', $prompt);
    }
}
