<?php
/**
 * Safe Markdown-to-HTML renderer for community content.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renders a compact, safe subset of Markdown (GitHub-flavoured-ish) to HTML.
 *
 * Supported: fenced code blocks, inline code, **bold**, *italic*, [links](url),
 * # headings, > blockquotes, - / 1. lists, --- rules, paragraphs and hard line
 * breaks. All output is escaped and passed through wp_kses with an explicit
 * allow-list so user content can never inject markup.
 */
final class Markdown
{
    /**
     * Renders Markdown text to sanitized HTML.
     */
    public static function render(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        // 1. Pull fenced code blocks out so they are protected from the rest.
        $code_blocks = [];
        $text        = preg_replace_callback('/`{3}[^\n`]*\n(.*?)`{3}/s', static function ($m) use (&$code_blocks): string {
            $key               = '%%CGBLOCK' . count($code_blocks) . '%%';
            $code_blocks[$key] = '<pre><code>' . htmlspecialchars(rtrim($m[1]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';

            return "\n" . $key . "\n";
        }, $text);

        // 2. Escape everything else so raw user HTML shows as text.
        $text = htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $lines = explode("\n", $text);
        $html  = self::render_blocks($lines, $code_blocks);

        return \wp_kses($html, self::allowed_tags());
    }

    /**
     * Walks the line array producing block-level HTML.
     *
     * @param string[]          $lines       Escaped input lines.
     * @param array<string,string> $code_blocks Placeholders => pre-rendered code HTML.
     */
    private static function render_blocks(array $lines, array $code_blocks): string
    {
        $out = [];
        $i   = 0;
        $n   = count($lines);

        while ($i < $n) {
            $line = $lines[$i];
            $trim = trim($line);

            if ($trim === '') {
                $i++;
                continue;
            }

            // Standalone code-block placeholder.
            if (isset($code_blocks[$trim])) {
                $out[] = $code_blocks[$trim];
                $i++;
                continue;
            }

            // ATX heading.
            if (preg_match('/^(#{1,4})\s+(.*)$/', $trim, $m)) {
                $level   = min(4, max(2, strlen($m[1])));
                $out[]   = '<h' . $level . '>' . self::inline($m[2]) . '</h' . $level . '>';
                $i++;
                continue;
            }

            // Horizontal rule.
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trim)) {
                $out[] = '<hr />';
                $i++;
                continue;
            }

            // Blockquote. ">" is escaped to "&gt;" in step 2, so match that.
            if (preg_match('/^&gt;\s?(.*)$/', $line, $m)) {
                $buf = [];
                while ($i < $n && preg_match('/^&gt;\s?(.*)$/', $lines[$i], $m2)) {
                    $buf[] = trim($m2[1]);
                    $i++;
                }
                $out[] = '<blockquote><p>' . self::inline(implode('<br>', $buf)) . '</p></blockquote>';
                continue;
            }

            // Unordered list.
            if (preg_match('/^\s*[-*+]\s+(.*)$/', $line, $m)) {
                $items = [];
                while ($i < $n && preg_match('/^\s*[-*+]\s+(.*)$/', $lines[$i], $m2)) {
                    $items[] = self::inline($m2[1]);
                    $i++;
                }
                $out[] = '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
                continue;
            }

            // Ordered list.
            if (preg_match('/^\s*\d+\.\s+(.*)$/', $line, $m)) {
                $items = [];
                while ($i < $n && preg_match('/^\s*\d+\.\s+(.*)$/', $lines[$i], $m2)) {
                    $items[] = self::inline($m2[1]);
                    $i++;
                }
                $out[] = '<ol><li>' . implode('</li><li>', $items) . '</li></ol>';
                continue;
            }

            // Paragraph: gather consecutive non-blank, non-block-starter lines.
            $buf = [];
            while ($i < $n
                && trim($lines[$i]) !== ''
                && ! preg_match('/^#{1,4}\s+/', trim($lines[$i]))
                && ! preg_match('/^&gt;\s?/', $lines[$i])
                && ! preg_match('/^\s*[-*+]\s+/', $lines[$i])
                && ! preg_match('/^\s*\d+\.\s+/', $lines[$i])
                && ! isset($code_blocks[trim($lines[$i])])
            ) {
                $buf[] = trim($lines[$i]);
                $i++;
            }

            $out[] = '<p>' . self::inline(implode("<br>\n", $buf)) . '</p>';
        }

        return implode("\n", $out);
    }

    /**
     * Applies inline Markdown transforms to a single line of already-escaped text.
     */
    private static function inline(string $text): string
    {
        // Inline code — decode the entity so we re-escape inside <code>.
        $text = preg_replace_callback('/`([^`]+)`/', static function ($m): string {
            $code = htmlspecialchars_decode($m[1], ENT_QUOTES);

            return '<code>' . htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        }, $text);

        // Safe links: http(s) and mailto only.
        $text = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/i', static function ($m): string {
            return '<a href="' . \esc_attr($m[2]) . '" rel="nofollow noopener" target="_blank">' . self::inline($m[1]) . '</a>';
        }, $text);

        // Bold then italic.
        $text = preg_replace('/\*\*([^\s*][^*]*[^\s*]|[^\s*])\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/__([^\s_][^_]*[^\s_]|[^\s_])__/', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<![a-zA-Z0-9*])\*([^\s*][^*]*[^\s*]|[^\s*])\*(?![a-zA-Z0-9*])/', '<em>$1</em>', $text);
        $text = preg_replace('/(?<![a-zA-Z0-9_])_([^\s_][^_]*[^\s_]|[^\s_])_(?![a-zA-Z0-9_])/', '<em>$1</em>', $text);

        return $text;
    }

    /**
     * Explicit allow-list for the rendered HTML.
     *
     * @return array<string, array<string,bool>>
     */
    private static function allowed_tags(): array
    {
        return [
            'p'          => [],
            'br'         => [],
            'hr'         => [],
            'strong'     => [],
            'em'         => [],
            'code'       => [],
            'pre'        => [],
            'blockquote' => [],
            'ul'         => [],
            'ol'         => [],
            'li'         => [],
            'h2'         => [],
            'h3'         => [],
            'h4'         => [],
            'a'          => [
                'href'    => true,
                'title'   => true,
                'rel'     => true,
                'target'  => true,
            ],
        ];
    }
}
