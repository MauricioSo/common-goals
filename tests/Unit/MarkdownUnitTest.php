<?php
/**
 * Unit tests for the safe Markdown renderer.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use CommonGoals\Markdown;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class MarkdownUnitTest extends UnitTestCase
{
    public function test_renders_common_inline_and_block_markdown(): void
    {
        $html = Markdown::render("## Heading\n\nHello **bold** and *italic* with `code`.\n\n- one\n- two\n\n1. first\n2. second\n\n> quoted");

        $this->assertStringContainsString('<h2>Heading</h2>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
        $this->assertStringContainsString('<ul><li>one</li><li>two</li></ul>', $html);
        $this->assertStringContainsString('<ol><li>first</li><li>second</li></ol>', $html);
        $this->assertStringContainsString('<blockquote><p>quoted</p></blockquote>', $html);
    }

    public function test_renders_safe_links_and_rejects_unsafe_link_schemes(): void
    {
        $html = Markdown::render('[site](https://example.test) [mail](mailto:test@example.test) [bad](javascript:alert(1))');

        $this->assertStringContainsString('<a href="https://example.test" rel="nofollow noopener" target="_blank">site</a>', $html);
        $this->assertStringContainsString('<a href="mailto:test@example.test" rel="nofollow noopener" target="_blank">mail</a>', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('[bad](javascript:alert(1))', $html);
    }

    public function test_escapes_raw_html_and_script_payloads(): void
    {
        $html = Markdown::render('<script>alert(1)</script><img src=x onerror=alert(1)> **ok**');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('<strong>ok</strong>', $html);
    }

    public function test_fenced_code_blocks_preserve_literal_markdown_and_escape_html(): void
    {
        $html = Markdown::render("```php\n<strong>not html</strong>\n**not bold**\n```");

        $this->assertStringContainsString('<pre><code>&lt;strong&gt;not html&lt;/strong&gt;', $html);
        $this->assertStringContainsString('**not bold**', $html);
        $this->assertStringNotContainsString('<strong>not html</strong>', $html);
    }

    public function test_empty_input_returns_empty_string(): void
    {
        $this->assertSame('', Markdown::render("\n \t\n"));
    }
}
