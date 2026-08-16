<?php
/**
 * REST API endpoints for Common Goals.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers read and write REST endpoints so that headless WordPress,
 * mobile apps and external integrations can access community data.
 *
 * Base namespace: /wp-json/common-goals/v1
 */
final class RestApi
{
    public const NAMESPACE = 'common-goals/v1';

    /**
     * Registers WordPress hooks.
     */
    public static function register_hooks(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    /**
     * Registers all REST routes.
     */
    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/goals', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_goals'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/communities', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_communities'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/goals/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_goal'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/contributions', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_contributions'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/contributions/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_contribution'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/contributions', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'create_contribution'],
            'permission_callback' => [self::class, 'check_write_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/guides', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_guides'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/guides/(?P<slug>[a-zA-Z0-9\-]+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_guide'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/vote', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'cast_vote'],
            'permission_callback' => [self::class, 'check_vote_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/bookmark', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'toggle_bookmark'],
            'permission_callback' => [self::class, 'check_vote_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/notifications/read', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'mark_notifications_read'],
            'permission_callback' => [self::class, 'check_vote_permission'],
        ]);
    }

    /**
     * Returns all active goals.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public static function get_goals(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $community_id = absint($request->get_param('community_id'));
        $where_sql    = "WHERE status = 'active'";

        if ($community_id > 0) {
            $where_sql .= $wpdb->prepare(' AND community_id = %d', $community_id);
        }

        $goals = $wpdb->get_results("SELECT id, community_id, title, description, beneficiary, status, created_at, updated_at FROM " . Database::goals_table() . " {$where_sql} ORDER BY created_at DESC LIMIT 100");

        return new \WP_REST_Response($goals, 200);
    }

    /**
     * Returns active communities.
     */
    public static function get_communities(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $communities = $wpdb->get_results('SELECT id, name, slug, description, status, created_at, updated_at FROM ' . Database::communities_table() . " WHERE status = 'active' ORDER BY name ASC LIMIT 100");

        return new \WP_REST_Response($communities, 200);
    }

    /**
     * Returns a single goal by ID.
     *
     * Returns only public fields; internal columns such as created_by are
     * intentionally omitted to avoid leaking account identifiers.
     */
    public static function get_goal(\WP_REST_Request $request): \WP_REST_Response
    {
        $goal_id      = absint($request['id']);
        $community_id = absint($request->get_param('community_id'));
        $goal         = Domain::get_active_goal($goal_id, $community_id > 0 ? $community_id : null);

        if (! $goal) {
            return new \WP_REST_Response(['message' => __('Goal not found.', 'common-goals')], 404);
        }

        $response_data = [
            'id'                      => (int) $goal->id,
            'community_id'            => (int) $goal->community_id,
            'title'                   => $goal->title,
            'description'             => $goal->description,
            'beneficiary'             => $goal->beneficiary,
            'allowed_contribution_types' => Domain::allowed_types_for_goal($goal),
            'status'                  => $goal->status,
            'created_at'              => $goal->created_at,
            'updated_at'              => $goal->updated_at,
        ];

        return new \WP_REST_Response($response_data, 200);
    }

    /**
     * Returns contributions with optional filters.
     */
    public static function get_contributions(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $goal_id      = absint($request->get_param('goal_id'));
        $community_id = absint($request->get_param('community_id'));
        $type         = sanitize_key($request->get_param('type') ?? '');
        $status       = sanitize_key($request->get_param('status') ?? '');
        $page     = max(1, absint($request->get_param('page') ?? 1));
        // Normalize per_page into [1, 50]. A zero or negative value previously
        // produced a division-by-zero in the total-pages calculation; clamp to
        // the minimum of 1 instead (API-007).
        $per_page = max(1, min(50, absint($request->get_param('per_page') ?? 20)));

        $where    = [];
        $params   = [];
        $visible  = Domain::PUBLIC_STATUSES;
        $placeholders = implode(',', array_fill(0, count($visible), '%s'));

        if ($goal_id > 0) {
            $where[]  = 'contributions.goal_id = %d';
            $params[] = $goal_id;
        }

        if ($community_id > 0) {
            $where[]  = 'goals.community_id = %d';
            $params[] = $community_id;
        }

        $where[]  = "contributions.status IN ({$placeholders})";

        foreach ($visible as $s) {
            $params[] = $s;
        }

        if ($type !== '' && in_array($type, Domain::CONTRIBUTION_TYPES, true)) {
            $where[]  = 'contributions.type = %s';
            $params[] = $type;
        }

        if ($status !== '' && in_array($status, $visible, true)) {
            $where[]  = 'contributions.status = %s';
            $params[] = $status;
        }

        $where_sql = implode(' AND ', $where);
        $offset    = ($page - 1) * $per_page;
        $params[]  = $per_page;
        $params[]  = $offset;

        $contributions = $wpdb->get_results(
            $wpdb->prepare("SELECT contributions.id, contributions.goal_id, goals.community_id, contributions.type, contributions.status, contributions.topic, contributions.title, contributions.body, contributions.created_at, contributions.updated_at FROM " . Database::contributions_table() . " contributions LEFT JOIN " . Database::goals_table() . " goals ON goals.id = contributions.goal_id WHERE {$where_sql} ORDER BY contributions.created_at DESC LIMIT %d OFFSET %d", ...$params)
        );

        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . Database::contributions_table() . " contributions LEFT JOIN " . Database::goals_table() . " goals ON goals.id = contributions.goal_id WHERE {$where_sql}", ...array_slice($params, 0, -2)));

        $response = new \WP_REST_Response($contributions, 200);
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($total / $per_page)));

        return $response;
    }

    /**
     * Returns a single contribution with its published responses.
     *
     * Only safe, public fields are returned. The user_id column is never
     * exposed to avoid user enumeration through the public REST endpoints.
     */
    public static function get_contribution(\WP_REST_Request $request): \WP_REST_Response
    {
        $id           = absint($request['id']);
        $contribution = Domain::get_visible_contribution($id);

        if (! $contribution) {
            return new \WP_REST_Response(['message' => __('Contribution not found.', 'common-goals')], 404);
        }

        global $wpdb;

        $raw_responses = $wpdb->get_results(
            $wpdb->prepare("SELECT id, contribution_id, body, created_at FROM " . Database::responses_table() . " WHERE contribution_id = %d AND status = 'published' ORDER BY created_at ASC LIMIT 100", $id)
        );

        $safe_responses = array_map(static function (object $response): array {
            return [
                'id'              => (int) $response->id,
                'contribution_id' => (int) $response->contribution_id,
                'body'            => $response->body,
                'created_at'      => $response->created_at,
            ];
        }, $raw_responses);

        $response_data = [
            'id'         => (int) $contribution->id,
            'goal_id'    => (int) $contribution->goal_id,
            'type'       => $contribution->type,
            'status'     => $contribution->status,
            'topic'      => $contribution->topic,
            'title'      => $contribution->title,
            'body'       => $contribution->body,
            'created_at' => $contribution->created_at,
            'updated_at' => $contribution->updated_at,
            'responses'  => $safe_responses,
        ];

        return new \WP_REST_Response($response_data, 200);
    }

    /**
     * Creates a contribution from a REST request.
     */
    public static function create_contribution(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $goal_id      = absint($request->get_param('goal_id'));
        $community_id = absint($request->get_param('community_id'));
        $type         = sanitize_key($request->get_param('type') ?? 'question');
        $topic        = sanitize_text_field($request->get_param('topic') ?? '');
        $title        = sanitize_text_field($request->get_param('title') ?? '');
        $body         = wp_kses_post($request->get_param('body') ?? '');

        $goal = Domain::get_active_goal($goal_id, $community_id > 0 ? $community_id : null);

        if (! $goal) {
            return new \WP_REST_Response(['message' => __('Invalid goal.', 'common-goals')], 400);
        }

        $allowed_types = Domain::allowed_types_for_goal($goal);

        if (! in_array($type, $allowed_types, true)) {
            return new \WP_REST_Response(['message' => __('Type not allowed for this goal.', 'common-goals')], 400);
        }

        if ($title === '' || $body === '') {
            return new \WP_REST_Response(['message' => __('Title and body are required.', 'common-goals')], 400);
        }

        if (mb_strlen($title) > Domain::MAX_TITLE_LENGTH || mb_strlen($topic) > Domain::MAX_TOPIC_LENGTH || mb_strlen($body) > Domain::MAX_BODY_LENGTH) {
            return new \WP_REST_Response(['message' => __('Submission exceeds the maximum allowed length.', 'common-goals')], 400);
        }

        $is_guest = ! is_user_logged_in();
        $status   = $is_guest ? 'pending' : 'open';

        if ($is_guest && ! (bool) get_option('common_goals_allow_guest_posting', 1)) {
            return new \WP_REST_Response(['message' => __('Guest posting is disabled.', 'common-goals')], 403);
        }

        if (! Domain::check_rate_limit('contribution')) {
            return new \WP_REST_Response(['message' => __('You are submitting too quickly. Please wait a few minutes.', 'common-goals')], 429);
        }

        if (Domain::is_spam($title . ' ' . $body, 'contribution')) {
            return new \WP_REST_Response(['message' => __('Your submission was flagged as spam and has not been published.', 'common-goals')], 403);
        }

        $now = current_time('mysql');

        $inserted = $wpdb->insert(
            Database::contributions_table(),
            [
                'goal_id'    => $goal_id,
                'user_id'    => get_current_user_id(),
                'type'       => $type,
                'status'     => $status,
                'topic'      => $topic,
                'title'      => $title,
                'body'       => $body,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new \WP_REST_Response(['message' => __('Database error.', 'common-goals')], 500);
        }

        $contribution_id = (int) $wpdb->insert_id;

        EventLogger::log('contribution', $contribution_id, 'contribution.created', [
            'goal_id'      => $goal_id,
            'community_id' => (int) $goal->community_id,
            'type'         => $type,
            'via_rest'     => true,
        ]);

        do_action('common_goals_contribution_created', $contribution_id, [
            'goal_id'      => $goal_id,
            'community_id' => (int) $goal->community_id,
            'status'       => $status,
            'type'         => $type,
        ]);

        return new \WP_REST_Response(['id' => $contribution_id, 'status' => $status], 201);
    }

    /**
     * Returns published guides.
     */
    public static function get_guides(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $limit        = max(1, min(50, absint($request->get_param('per_page') ?? 20)));
        $community_id = absint($request->get_param('community_id'));
        $where_sql    = "WHERE guides.status = 'published'";
        $params       = [];

        if ($community_id > 0) {
            $where_sql .= ' AND goals.community_id = %d';
            $params[] = $community_id;
        }

        $params[] = $limit;

        $guides = $wpdb->get_results(
            $wpdb->prepare("SELECT guides.id, guides.contribution_id, goals.community_id, guides.slug, guides.title, guides.content, guides.created_at, guides.updated_at FROM " . Database::guides_table() . " guides LEFT JOIN " . Database::contributions_table() . " contributions ON contributions.id = guides.contribution_id LEFT JOIN " . Database::goals_table() . " goals ON goals.id = contributions.goal_id {$where_sql} ORDER BY guides.updated_at DESC LIMIT %d", ...$params)
        );

        return new \WP_REST_Response($guides, 200);
    }

    /**
     * Returns a single published guide by slug.
     */
    public static function get_guide(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $slug  = sanitize_title($request['slug']);
        $guide = $wpdb->get_row(
            $wpdb->prepare("SELECT id, contribution_id, slug, title, content, status, created_at, updated_at FROM " . Database::guides_table() . " WHERE slug = %s AND status = 'published' LIMIT 1", $slug)
        );

        if (! $guide) {
            return new \WP_REST_Response(['message' => __('Guide not found.', 'common-goals')], 404);
        }

        return new \WP_REST_Response($guide, 200);
    }

    /**
     * Permission callback for write operations.
     *
     * @return bool
     */
    public static function check_write_permission(): bool
    {
        if (is_user_logged_in()) {
            return true;
        }

        return (bool) get_option('common_goals_allow_guest_posting', 1);
    }

    /**
     * Permission callback for voting. Voting is strictly limited to logged-in
     * users to prevent ballot stuffing and to attribute every vote.
     */
    public static function check_vote_permission(): bool
    {
        return is_user_logged_in();
    }

    /**
     * Casts an upvote or downvote on a contribution or response.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public static function cast_vote(\WP_REST_Request $request): \WP_REST_Response
    {
        $object_type = sanitize_key($request->get_param('object_type') ?? '');
        $object_id   = absint($request->get_param('object_id'));
        $value       = (int) $request->get_param('value');

        if (! in_array($object_type, Domain::VOTE_OBJECT_TYPES, true) || $object_id <= 0) {
            return new \WP_REST_Response(['message' => __('Invalid vote target.', 'common-goals')], 400);
        }

        if (! Domain::check_rate_limit('vote')) {
            return new \WP_REST_Response(['message' => __('You are voting too quickly. Please slow down.', 'common-goals')], 429);
        }

        $result = Domain::cast_vote($object_type, $object_id, $value);

        return new \WP_REST_Response($result, 200);
    }

    /**
     * Toggles a bookmark (saved thread) for the logged-in user.
     */
    public static function toggle_bookmark(\WP_REST_Request $request): \WP_REST_Response
    {
        $contribution_id = absint($request->get_param('contribution_id'));

        if ($contribution_id <= 0) {
            return new \WP_REST_Response(['message' => __('Invalid thread.', 'common-goals')], 400);
        }

        $bookmarked = Domain::toggle_bookmark($contribution_id);

        return new \WP_REST_Response(['bookmarked' => $bookmarked], 200);
    }

    /**
     * Marks one or all notifications as read for the logged-in user.
     */
    public static function mark_notifications_read(\WP_REST_Request $request): \WP_REST_Response
    {
        $user_id = get_current_user_id();

        if ($user_id <= 0) {
            return new \WP_REST_Response(['message' => __('Login required.', 'common-goals')], 401);
        }

        $notification_id = absint($request->get_param('id'));
        $all             = (bool) $request->get_param('all');

        if ($all) {
            InAppNotifications::mark_all_read($user_id);
        } else {
            InAppNotifications::mark_read($notification_id, $user_id);
        }

        return new \WP_REST_Response(['unread' => InAppNotifications::unread_count($user_id)], 200);
    }
}
