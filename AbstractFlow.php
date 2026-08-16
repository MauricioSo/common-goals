<?php
/**
 * Shared base for every AI flow.
 *
 * @package CommonGoals\AI\Flow
 */

namespace CommonGoals\AI\Flow;

use CommonGoals\AI\BudgetGuard;
use CommonGoals\AI\Client;
use CommonGoals\AI\CompletionClient;
use CommonGoals\AI\CompletionResult;
use CommonGoals\AI\Prompts;
use CommonGoals\AI\Settings;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Encapsulates the lifecycle shared by every flow: permission/budget gate,
 * prompt assembly, provider call, output shaping, budget recording and a
 * structured response envelope returned to the REST layer.
 *
 * Concrete flows implement {@see self::build_prompt()} and
 * {@see self::shape_output()} only; everything else is uniform so the
 * security and observability rules cannot be skipped per flow.
 */
abstract class AbstractFlow
{
    public string $flow = 'abstract';

    /**
     * Whether this flow requires a logged-in user. Member flows allow guests
     * to match the existing contribution policy; staff flows always require
     * authentication and a community-scoped capability.
     */
    public bool $requires_login = true;

    /**
     * Optional community-scoped capability ('moderate', 'publish_guides').
     * Empty string means no community capability is required.
     */
    public string $capability = '';

    /**
     * Returns the flow identifier.
     */
    public function id(): string
    {
        return $this->flow;
    }

    /**
     * Executes the flow and returns a structured envelope.
     *
     * @param array<string, mixed> $input Validated input from the request.
     * @return array{ok: bool, data?: array<string,mixed>, error?: array{code: string, message: string}}
     */
    public function run(array $input): array
    {
        $gate = $this->authorize($input);
        if (! $gate['allowed']) {
            return $this->error($gate['code'] ?? 'forbidden', $gate['message'] ?? '');
        }

        $guard = BudgetGuard::can_run($this->flow);
        if (! $guard['allowed']) {
            return $this->error($guard['code'], $guard['message']);
        }

        $prompt = $this->build_prompt($input);
        if ($prompt === null) {
            return $this->error('invalid_input', __('The request did not contain enough context for the assistant.', 'common-goals'));
        }

        $result = $this->client()->complete(
            Prompts::system(),
            [['role' => 'user', 'content' => $prompt]],
            $this->call_options()
        );

        BudgetGuard::record($this->flow, $result);

        if (! $result->ok) {
            return $this->error($result->errorCode, $result->errorMessage);
        }

        $shaped = $this->shape_output($result);

        /**
         * Fires after a flow produced a shaped result, allowing extensions
         * to cache, audit or rewrite suggestions before they leave the API.
         *
         * @param array<string,mixed> $shaped
         * @param string              $flow
         */
        do_action('common_goals_ai_flow_result', $shaped, $this->flow);

        return ['ok' => true, 'data' => $shaped];
    }

    /**
     * Returns the per-call options (json mode, temperature, max tokens).
     *
     * @return array<string, mixed>
     */
    protected function call_options(): array
    {
        return [
            'json_mode'   => true,
            'temperature' => Settings::temperature(),
            'max_tokens'  => Settings::max_tokens(),
        ];
    }

    /**
     * Checks authentication and capability requirements.
     *
     * @param array<string, mixed> $input
     * @return array{allowed: bool, code?: string, message?: string}
     */
    protected function authorize(array $input): array
    {
        if ($this->requires_login && ! is_user_logged_in()) {
            return [
                'allowed' => false,
                'code'    => 'login_required',
                'message' => __('Please log in to use the assistant.', 'common-goals'),
            ];
        }

        if ($this->capability !== '') {
            if (! $this->user_can($this->capability, $input)) {
                return [
                    'allowed' => false,
                    'code'    => 'forbidden',
                    'message' => __('You are not authorized to use this assistant flow.', 'common-goals'),
                ];
            }
        }

        return ['allowed' => true];
    }

    /**
     * Returns true when the current user holds the requested capability,
     * honoring community-scoped roles when a community_id is provided.
     *
     * @param string              $capability 'moderate' or 'publish_guides'.
     * @param array<string,mixed> $input
     */
    protected function user_can(string $capability, array $input): bool
    {
        $community_id = (int) ($input['community_id'] ?? 0);

        switch ($capability) {
            case 'moderate':
                return function_exists('CommonGoals\Domain::current_user_can_moderate_community')
                    ? \CommonGoals\Domain::current_user_can_moderate_community($community_id)
                    : current_user_can('moderate_common_goals');
            case 'publish_guides':
                return function_exists('CommonGoals\Domain::current_user_can_publish_guides_for_community')
                    ? \CommonGoals\Domain::current_user_can_publish_guides_for_community($community_id)
                    : current_user_can('publish_common_goals_guides');
            default:
                return current_user_can('manage_common_goals');
        }
    }

    /**
     * Builds the user prompt from validated input. Return null to abort.
     *
     * @param array<string, mixed> $input
     */
    abstract protected function build_prompt(array $input): ?string;

    /**
     * Shapes the model output into the response payload.
     */
    abstract protected function shape_output(CompletionResult $result): array;

    /**
     * Builds a standardized error envelope.
     *
     * @return array{ok: bool, error: array{code: string, message: string}}
     */
    protected function error(string $code, string $message): array
    {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];
    }

    /**
     * Returns a human-friendly label for this flow.
     */
    public function label(): string
    {
        return Settings::flow_meta($this->flow)['label'];
    }

    /**
     * Resolves the AI client used for this run.
     *
     * The `common_goals_ai_client` filter allows tests and integrations to
     * inject a stub; by default a real {@see Client} is used.
     */
    protected function client(): CompletionClient
    {
        /**
         * Filters the AI client instance used for a flow run.
         *
         * @param CompletionClient|null $client
         * @param string                $flow
         */
        $override = apply_filters('common_goals_ai_client', null, $this->flow);

        return $override instanceof CompletionClient ? $override : new Client();
    }
}
