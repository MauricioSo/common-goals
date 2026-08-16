<?php
/**
 * Property-based tests for Markdown safety invariants.
 *
 * @package CommonGoals\Tests\Property
 */

namespace CommonGoals\Tests\Property;

use CommonGoals\Markdown;
use CommonGoals\Tests\Property\Support\PropertyRng;
use CommonGoals\Tests\Property\Support\PropertyTestCase;

final class MarkdownPropertyTest extends PropertyTestCase
{
    public function test_pb_md_001_random_input_never_emits_executable_html_or_javascript_links(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            $tokens = [];
            $count = $rng->between(1, 30);
            for ($i = 0; $i < $count; $i++) {
                $tokens[] = $rng->element([
                    $rng->string(20, 'abcdefghijklmnopqrstuvwxyz0123456789 _-*`[]().:/@'),
                    '<script>alert(1)</script>',
                    '<img src=x onerror=alert(1)>',
                    '[bad](javascript:alert(1))',
                    '[ok](https://example.test/path)',
                    "```\n<script>x</script>\n```",
                    '> quote',
                    '- item',
                    '1. item',
                    '## heading',
                ]);
            }

            $html = Markdown::render(implode("\n", $tokens));

            $this->assertStringNotContainsString('<script', strtolower($html));
            $this->assertStringNotContainsString('<img', strtolower($html));
            $this->assertStringNotContainsString('href="javascript:', strtolower($html));
        }, 300, 'PB-MD-001');
    }
}
