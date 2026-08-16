<?php
/**
 * Centralized access to the AI assistant settings.
 *
 * @package CommonGoals\AI
 */

namespace CommonGoals\AI;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reads the AI configuration from a single options row and exposes typed,
 * default-safe accessors used by every flow, the REST router and the admin
 * settings screen.
 *
 * The API key is stored in the option but every accessor redacts it; it is
 * only retrieved through {@see self::api_key()} which callers must keep out
 * of logs, responses and audit data.
 */
final class Settings
{
    public const OPTION_NAME = 'common_goals_ai_settings';

    public const DEFAULT_MODEL        = 'deepseek-v4-flash';
    public const DEFAULT_BASE_URL     = 'https://api.deepseek.com';
    public const DEFAULT_TEMPERATURE  = 0.3;
    public const DEFAULT_MAX_TOKENS   = 1200;
    public const DEFAULT_TIMEOUT      = 30;
    public const DEFAULT_MONTHLY_BUDGET = 10.0;

    /**
     * Maps a flow identifier to its human label and default enabled state.
     *
     * The MVP member-facing flows ship enabled; the staff Phase-2 flows ship
     * disabled until a site admin turns them on.
     */
    public const FLOW_DEFAULTS = [
        'discover'  => ['label' => 'Discover related content', 'enabled' => true,  'phase' => 'mvp'],
        'compose'   => ['label' => 'Improve contribution drafts', 'enabled' => true,  'phase' => 'mvp'],
        'answer'    => ['label' => 'Draft responses with sources', 'enabled' => true,  'phase' => 'mvp'],
        'summarize' => ['label' => 'Summarize long threads', 'enabled' => true,  'phase' => 'mvp'],
        'organize'  => ['label' => 'Suggest tags and relations', 'enabled' => false, 'phase' => 'phase-2'],
        'moderate'  => ['label' => 'Prioritize moderation queue', 'enabled' => false, 'phase' => 'phase-2'],
        'guide'     => ['label' => 'Draft living guides', 'enabled' => false, 'phase' => 'phase-2'],
    ];

    /**
     * Returns the whole settings array merged with defaults.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION_NAME, []);
        $stored = is_array($stored) ? $stored : [];

        $merged = array_merge(self::defaults(), $stored);

        // Deep-merge enabled_flows so unspecified flows keep their default state
        // instead of being wiped by a partial stored array.
        $stored_flows = is_array($stored['enabled_flows'] ?? null) ? $stored['enabled_flows'] : [];
        $merged['enabled_flows'] = array_merge(self::defaults()['enabled_flows'], $stored_flows);

        return $merged;
    }

    /**
     * Returns the default settings shape.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $enabled = [];
        foreach (self::FLOW_DEFAULTS as $id => $meta) {
            $enabled[$id] = $meta['enabled'];
        }

        return [
            'api_key'           => '',
            'base_url'          => self::DEFAULT_BASE_URL,
            'model'             => self::DEFAULT_MODEL,
            'temperature'       => self::DEFAULT_TEMPERATURE,
            'max_tokens'        => self::DEFAULT_MAX_TOKENS,
            'timeout'           => self::DEFAULT_TIMEOUT,
            'enabled_flows'     => $enabled,
            'monthly_budget_usd' => self::DEFAULT_MONTHLY_BUDGET,
            'consent_notice'    => 'This community uses an AI assistant to suggest content. Human moderators review all sensitive actions.',
            'share_content'     => true,
        ];
    }

    /**
     * Returns the configured API key (empty string when unset).
     */
    public static function api_key(): string
    {
        return (string) (self::all()['api_key'] ?? '');
    }

    /**
     * Returns true when an API key has been configured.
     */
    public static function is_configured(): bool
    {
        return self::api_key() !== '';
    }

    public static function base_url(): string
    {
        return rtrim((string) (self::all()['base_url'] ?? self::DEFAULT_BASE_URL), '/');
    }

    public static function model(): string
    {
        return (string) (self::all()['model'] ?? self::DEFAULT_MODEL);
    }

    public static function temperature(): float
    {
        return (float) (self::all()['temperature'] ?? self::DEFAULT_TEMPERATURE);
    }

    public static function max_tokens(): int
    {
        return (int) (self::all()['max_tokens'] ?? self::DEFAULT_MAX_TOKENS);
    }

    public static function timeout(): int
    {
        $value = (int) (self::all()['timeout'] ?? self::DEFAULT_TIMEOUT);
        return $value > 0 ? min(120, $value) : self::DEFAULT_TIMEOUT;
    }

    public static function monthly_budget(): float
    {
        return (float) (self::all()['monthly_budget_usd'] ?? self::DEFAULT_MONTHLY_BUDGET);
    }

    public static function consent_notice(): string
    {
        return (string) (self::all()['consent_notice'] ?? '');
    }

    /**
     * Whether the admin has authorized sending community content to the model.
     * When false, only non-content flows (none by default) may run.
     */
    public static function share_content(): bool
    {
        return (bool) (self::all()['share_content'] ?? true);
    }

    /**
     * Whether a given flow is enabled in settings.
     */
    public static function is_flow_enabled(string $flow): bool
    {
        $enabled = self::all()['enabled_flows'] ?? [];

        return (bool) ($enabled[$flow] ?? self::FLOW_DEFAULTS[$flow]['enabled'] ?? false);
    }

    /**
     * Returns the flow metadata (label, phase, default enabled).
     *
     * @return array{label: string, enabled: bool, phase: string}
     */
    public static function flow_meta(string $flow): array
    {
        return self::FLOW_DEFAULTS[$flow] ?? ['label' => $flow, 'enabled' => false, 'phase' => 'phase-2'];
    }

    /**
     * Returns the list of all flow identifiers.
     *
     * @return string[]
     */
    public static function flow_ids(): array
    {
        return array_keys(self::FLOW_DEFAULTS);
    }

    /**
     * Returns a masked representation of the API key for display.
     */
    public static function masked_api_key(): string
    {
        $key = self::api_key();

        if ($key === '') {
            return '';
        }

        $length = strlen($key);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($key, 0, 4) . str_repeat('*', max(4, $length - 8)) . substr($key, -4);
    }
}
