<?php
/**
 * REST router for the AI assistant flows.
 *
 * @package CommonGoals\AI
 */

namespace CommonGoals\AI;

use CommonGoals\AI\Flow\AnswerFlow;
use CommonGoals\AI\Flow\ComposeFlow;
use CommonGoals\AI\Flow\DiscoverFlow;
use CommonGoals\AI\Flow\GuideFlow;
use CommonGoals\AI\Flow\ModerateFlow;
use CommonGoals\AI\Flow\OrganizeFlow;
use CommonGoals\AI\Flow\SummarizeFlow;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers the REST surface under /wp-json/common-goals/v1/ai/* and maps
 * each route to a flow. All routes require a nonce and go through the
 * {@see AbstractFlow} lifecycle, so authorization, budget and auditing are
 * enforced uniformly.
 */
final class AiRouter
{
    public const NAMESPACE = 'common-goals/v1';
    public const ROUTE_PREFIX = '/ai';

    /**
     * Registers WordPress hooks.
     */
    public static function register_hooks(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    /**
     * Registers all AI REST routes.
     */
    public static function register_routes(): void
    {
        $routes = [
            'discover'  => ['flow' => new DiscoverFlow(),  'input' => [self::class, 'read_discover']],
            'compose'   => ['flow' => new ComposeFlow(),   'input' => [self::class, 'read_compose']],
            'answer'    => ['flow' => new AnswerFlow(),    'input' => [self::class, 'read_answer']],
            'summarize' => ['flow' => new SummarizeFlow(), 'input' => [self::class, 'read_summarize']],
            'organize'  => ['flow' => new OrganizeFlow(),  'input' => [self::class, 'read_organize']],
            'moderate'  => ['flow' => new ModerateFlow(),  'input' => [self::class, 'read_moderate']],
            'guide'     => ['flow' => new GuideFlow(),     'input' => [self::class, 'read_guide']],
        ];

        foreach ($routes as $name => $config) {
            $flow = $config['flow'];
            register_rest_route(self::NAMESPACE, self::ROUTE_PREFIX . '/' . $name, [
                'methods'             => 'POST',
                'callback'            => static function (\WP_REST_Request $request) use ($flow, $config) {
                    return self::dispatch($flow, $config['input']($request));
                },
                'permission_callback' => '__return_true',
            ]);
        }

        register_rest_route(self::NAMESPACE, self::ROUTE_PREFIX . '/status', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'status'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Runs a flow and converts its envelope to a REST response.
     *
     * @param array<string, mixed> $input
     */
    public static function dispatch(Flow\AbstractFlow $flow, array $input): \WP_REST_Response
    {
        $result = $flow->run($input);

        if ($result['ok']) {
            return new \WP_REST_Response($result['data'], 200);
        }

        $error = $result['error'] ?? ['code' => 'error', 'message' => 'Unknown error.'];

        $status = match ($error['code']) {
            'login_required'    => 401,
            'forbidden'         => 403,
            'disabled', 'not_configured', 'no_consent' => 503,
            'rate_limited'      => 429,
            'budget_exceeded'   => 402,
            'invalid_input'     => 400,
            default             => 502,
        };

        return new \WP_REST_Response($error, $status);
    }

    /**
     * Returns the assistant status for the frontend (enabled flows, budget).
     */
    public static function status(\WP_REST_Request $request): \WP_REST_Response
    {
        $flows = [];
        foreach (Settings::flow_ids() as $id) {
            $flows[$id] = [
                'enabled' => Settings::is_configured() && Settings::is_flow_enabled($id),
                'phase'   => Settings::flow_meta($id)['phase'],
                'label'   => Settings::flow_meta($id)['label'],
            ];
        }

        return new \WP_REST_Response([
            'configured'    => Settings::is_configured(),
            'flows'         => $flows,
            'consent_notice'=> Settings::consent_notice(),
            'budget'        => [
                'monthly_usd' => Settings::monthly_budget(),
                'spent_usd'   => BudgetGuard::monthly_spend(),
            ],
        ], 200);
    }

    /**
     * @return array<string, mixed>
     */
    public static function read_discover(\WP_REST_Request $request): array
    {
        return [
            'query'        => sanitize_text_field(wp_unslash($request->get_param('query') ?? '')),
            'goal_id'      => absint($request->get_param('goal_id')),
            'community_id' => absint($request->get_param('community_id')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function read_compose(\WP_REST_Request $request): array
    {
        return [
            'draft'        => sanitize_textarea_field(wp_unslash($request->get_param('draft') ?? '')),
            'goal_id'      => absint($request->get_param('goal_id')),
            'community_id' => absint($request->get_param('community_id')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function read_answer(\WP_REST_Request $request): array
    {
        return [
            'contribution_id' => absint($request->get_param('contribution_id')),
            'community_id'    => absint($request->get_param('community_id')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function read_summarize(\WP_REST_Request $request): array
    {
        return [
            'contribution_id' => absint($request->get_param('contribution_id')),
            'community_id'    => absint($request->get_param('community_id')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function read_organize(\WP_REST_Request $request): array
    {
        return [
            'contribution_ids' => array_map('absint', (array) ($request->get_param('contribution_ids') ?? [])),
            'community_id'     => absint($request->get_param('community_id')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function read_moderate(\WP_REST_Request $request): array
    {
        return [
            'community_id' => absint($request->get_param('community_id')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function read_guide(\WP_REST_Request $request): array
    {
        return [
            'contribution_ids' => array_map('absint', (array) ($request->get_param('contribution_ids') ?? [])),
            'community_id'     => absint($request->get_param('community_id')),
        ];
    }
}
