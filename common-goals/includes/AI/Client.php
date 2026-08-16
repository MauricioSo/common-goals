<?php
/**
 * DeepSeek API client (OpenAI-compatible Chat Completions).
 *
 * @package CommonGoals\AI
 */

namespace CommonGoals\AI;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thin, dependency-free HTTP client around the DeepSeek Chat Completions API.
 *
 * The provider is encapsulated here so the rest of the plugin never touches
 * HTTP transport or vendor specifics; swapping models only requires changing
 * {@see Settings::model()} or this single class.
 *
 * All responses are returned as a normalized {@see CompletionResult}; callers
 * must still validate model output through {@see OutputValidator} because the
 * output of a language model is untrusted content.
 */
final class Client implements CompletionClient
{
    public const CHAT_COMPLETIONS_PATH = '/chat/completions';

    /**
     * Sends a Chat Completions request and returns a normalized result.
     *
     * @param string[]              $system System prompt lines.
     * @param array<int, mixed>     $messages OpenAI-style messages.
     * @param array<string, mixed>  $options  Optional overrides (temperature, max_tokens, json_mode).
     * @return CompletionResult
     */
    public function complete(array $system, array $messages, array $options = []): CompletionResult
    {
        $settings = Settings::all();

        if (! Settings::is_configured()) {
            return CompletionResult::error('not_configured', 'The AI assistant is not configured.');
        }

        $payload = $this->build_payload($settings, $system, $messages, $options);
        $url     = Settings::base_url() . self::CHAT_COMPLETIONS_PATH;
        $started = microtime(true);

        $response = wp_remote_post($url, [
            'timeout' => Settings::timeout(),
            'headers' => [
                'Authorization' => 'Bearer ' . Settings::api_key(),
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
        ]);

        $latency = (int) round((microtime(true) - $started) * 1000);

        if (is_wp_error($response)) {
            /** \WP_Error $response */
            return CompletionResult::error('transport', $response->get_error_message(), $latency);
        }

        /** @var array<string, mixed> $http */
        $http    = $response;
        $code    = (int) wp_remote_retrieve_response_code($http);
        $body    = wp_remote_retrieve_body($http);
        $decoded = json_decode($body, true);

        if ($code >= 400) {
            $message = is_array($decoded) && isset($decoded['error']['message'])
                ? (string) $decoded['error']['message']
                : 'The AI provider returned HTTP ' . $code . '.';

            return CompletionResult::error('provider_http', $message, $latency, $code);
        }

        if (! is_array($decoded)) {
            return CompletionResult::error('provider_parse', 'The AI provider returned an unreadable response.', $latency);
        }

        return $this->parse_success($decoded, $latency);
    }

    /**
     * Builds the request body from settings and call options.
     *
     * @param array<string, mixed>  $settings Resolved settings array.
     * @param string[]              $system   System prompt lines.
     * @param array<int, mixed>     $messages User/assistant messages.
     * @param array<string, mixed>  $options  Per-call overrides.
     * @return array<string, mixed>
     */
    private function build_payload(array $settings, array $system, array $messages, array $options): array
    {
        $system_text = trim(implode("\n", array_filter($system)));

        $payload_messages = [];
        if ($system_text !== '') {
            $payload_messages[] = ['role' => 'system', 'content' => $system_text];
        }

        foreach ($messages as $message) {
            if (is_array($message) && isset($message['role'], $message['content'])) {
                $payload_messages[] = [
                    'role'    => (string) $message['role'],
                    'content' => (string) $message['content'],
                ];
            }
        }

        $payload = [
            'model'       => (string) ($options['model'] ?? $settings['model']),
            'messages'    => $payload_messages,
            'temperature' => isset($options['temperature']) ? (float) $options['temperature'] : (float) $settings['temperature'],
            'max_tokens'  => isset($options['max_tokens']) ? (int) $options['max_tokens'] : (int) $settings['max_tokens'],
            'stream'      => false,
        ];

        if (! empty($options['json_mode'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return $payload;
    }

    /**
     * Parses a successful provider response into a CompletionResult.
     *
     * @param array<string, mixed> $decoded Decoded JSON body.
     * @param int                  $latency  Request latency in milliseconds.
     */
    private function parse_success(array $decoded, int $latency): CompletionResult
    {
        $choices = $decoded['choices'] ?? [];
        $choice  = is_array($choices) && isset($choices[0]) ? $choices[0] : null;

        if (! is_array($choice) || ! isset($choice['message']['content'])) {
            return CompletionResult::error('provider_empty', 'The AI provider returned no content.', $latency);
        }

        $content = (string) $choice['message']['content'];
        $usage   = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];

        return new CompletionResult(
            ok: true,
            content: $content,
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            model: (string) ($decoded['model'] ?? Settings::model()),
            latencyMs: $latency,
            errorCode: '',
            errorMessage: '',
            httpCode: 200
        );
    }
}
