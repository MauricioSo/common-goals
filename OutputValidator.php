<?php
/**
 * Hardening for untrusted model output.
 *
 * @package CommonGoals\AI
 */

namespace CommonGoals\AI;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Treats every model response as attacker-controlled content: decodes JSON,
 * shapes it against a per-flow schema, strips disallowed keys, clamps types
 * and runs the text through wp_kses so it is safe to render or store.
 *
 * No flow is allowed to use raw CompletionResult::content() for a write
 * operation without first passing it through this class.
 */
final class OutputValidator
{
    /**
     * Decodes a JSON object response and returns only the allowed keys with
     * coerced types. Missing keys are filled with their defaults.
     *
     * @param string                $content  Raw model output.
     * @param array<string, mixed>  $schema   Map of key => ['type' => 'string'|'int'|'float'|'bool'|'list'|'list_of_strings', 'max' => int, 'default' => mixed].
     * @return array<string, mixed>
     */
    public static function shape(string $content, array $schema): array
    {
        $decoded = json_decode(trim($content), true);

        if (! is_array($decoded)) {
            $decoded = [];
        }

        $shaped = [];
        foreach ($schema as $key => $rule) {
            $type    = $rule['type'] ?? 'string';
            $default = $rule['default'] ?? null;
            $value   = array_key_exists($key, $decoded) ? $decoded[$key] : $default;

            $shaped[$key] = self::coerce($value, $type, $rule);
        }

        return $shaped;
    }

    /**
     * Coerces a single value to the declared type with clamping.
     *
     * @param mixed               $value Raw value.
     * @param string              $type  Declared type.
     * @param array<string,mixed> $rule  Rule options (max items, max length).
     * @return mixed
     */
    private static function coerce($value, string $type, array $rule)
    {
        switch ($type) {
            case 'string':
                $value = self::clean_text((string) $value);
                $max   = $rule['max'] ?? 5000;
                return mb_substr($value, 0, (int) $max);
            case 'int':
                return max(0, (int) $value);
            case 'float':
                $value = (float) $value;
                return $value < 0 ? 0.0 : round($value, 4);
            case 'bool':
                return (bool) $value;
            case 'list':
                $items = is_array($value) ? array_values($value) : [];
                $max   = $rule['max'] ?? 20;
                $out   = [];
                foreach ($items as $item) {
                    if (count($out) >= $max) {
                        break;
                    }
                    $out[] = is_array($item) ? self::shape_array($item) : self::clean_text((string) $item);
                }
                return $out;
            case 'list_of_strings':
                $items = is_array($value) ? array_values($value) : [];
                $max   = $rule['max'] ?? 20;
                $out   = [];
                foreach ($items as $item) {
                    if (! is_string($item) && ! is_numeric($item)) {
                        continue;
                    }
                    if (count($out) >= $max) {
                        break;
                    }
                    $out[] = mb_substr(self::clean_text((string) $item), 0, 300);
                }
                return $out;
            default:
                return $rule['default'] ?? null;
        }
    }

    /**
     * Recursively shapes a nested array item (used for 'list' of objects).
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function shape_array(array $item): array
    {
        $out = [];
        foreach ($item as $key => $value) {
            $key = self::clean_text((string) $key);
            if ($key === '') {
                continue;
            }
            $out[$key] = is_array($value) ? self::shape_array($value) : self::clean_text((string) $value);
        }
        return $out;
    }

    /**
     * Returns text safe for storage and display: strips tags except a small
     * Markdown-friendly subset is NOT allowed here — we keep raw text and let
     * the Markdown renderer escape at presentation time.
     */
    public static function clean_text(string $text): string
    {
        $text = wp_strip_all_tags($text);

        // Remove control characters that break storage and JSON.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * Returns the estimated USD cost of a completion.
     *
     * Pricing uses the published DeepSeek V4 Flash rates (USD per million
     * tokens) and falls back to zero when tokens are missing. Rates are kept
     * in one place so they can be updated without touching the call sites.
     */
    public static function estimate_cost(CompletionResult $result): float
    {
        $per_million = [
            'prompt'     => 0.14,
            'completion' => 0.28,
        ];

        /**
         * Filters the per-million-token pricing used for budget estimates.
         *
         * @param array<string,float> $per_million
         * @param string              $model
         */
        $per_million = (array) apply_filters('common_goals_ai_pricing', $per_million, $result->model);

        $prompt = ($result->promptTokens / 1000000) * (float) ($per_million['prompt'] ?? 0.14);
        $comp   = ($result->completionTokens / 1000000) * (float) ($per_million['completion'] ?? 0.28);

        return round($prompt + $comp, 6);
    }
}
