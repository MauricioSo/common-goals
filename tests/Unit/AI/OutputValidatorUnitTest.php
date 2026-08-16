<?php
/**
 * Unit tests for the OutputValidator.
 *
 * @package CommonGoals\Tests\Unit\AI
 */

namespace CommonGoals\Tests\Unit\AI;

use CommonGoals\AI\CompletionResult;
use CommonGoals\AI\OutputValidator;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class OutputValidatorUnitTest extends UnitTestCase
{
    public function test_shape_returns_defaults_when_json_is_invalid(): void
    {
        $shaped = OutputValidator::shape('not json', [
            'title' => ['type' => 'string', 'default' => ''],
            'count' => ['type' => 'int', 'default' => 3],
        ]);

        $this->assertSame('', $shaped['title']);
        $this->assertSame(3, $shaped['count']);
    }

    public function test_shape_strips_disallowed_keys_and_clamps_lengths(): void
    {
        $json = json_encode([
            'title' => '<script>x</script>Hello',
            'body'  => str_repeat('a', 5000),
            'evil'  => 'should be dropped',
        ]);

        $shaped = OutputValidator::shape($json, [
            'title' => ['type' => 'string', 'max' => 20, 'default' => ''],
            'body'  => ['type' => 'string', 'max' => 100, 'default' => ''],
        ]);

        // Tags are stripped but inner text is preserved, matching wp_strip_all_tags.
        $this->assertSame('xHello', $shaped['title']);
        $this->assertSame(100, mb_strlen($shaped['body']));
        $this->assertArrayNotHasKey('evil', $shaped);
    }

    public function test_shape_coerces_list_of_strings_and_filters_non_strings(): void
    {
        $json = json_encode(['items' => ['ok', 7, ['nested'], 'also-ok']]);

        $shaped = OutputValidator::shape($json, [
            'items' => ['type' => 'list_of_strings', 'max' => 5, 'default' => []],
        ]);

        $this->assertSame(['ok', '7', 'also-ok'], $shaped['items']);
    }

    public function test_shape_clamps_negative_floats_and_ints(): void
    {
        $json = json_encode(['confidence' => -0.5, 'tokens' => -12]);

        $shaped = OutputValidator::shape($json, [
            'confidence' => ['type' => 'float', 'default' => 0.0],
            'tokens'     => ['type' => 'int', 'default' => 0],
        ]);

        $this->assertSame(0.0, $shaped['confidence']);
        $this->assertSame(0, $shaped['tokens']);
    }

    public function test_estimate_cost_uses_default_pricing(): void
    {
        $result = new CompletionResult(
            ok: true,
            content: '',
            promptTokens: 1000000,
            completionTokens: 1000000,
            model: 'deepseek-v4-flash',
            latencyMs: 0,
            errorCode: '',
            errorMessage: '',
            httpCode: 200
        );

        // 1M prompt @ 0.14 + 1M completion @ 0.28 = 0.42
        $this->assertSame(0.42, OutputValidator::estimate_cost($result));
    }

    public function test_clean_text_strips_tags_and_control_chars(): void
    {
        $clean = OutputValidator::clean_text("  <b>hi</b>\x00\x07 there  ");

        $this->assertSame('hi there', $clean);
    }
}
