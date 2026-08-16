<?php
/**
 * Frontend shortcode for the public community board.
 *
 * @package CommonGoals
 */

namespace CommonGoals\Frontend;

use CommonGoals\Database;
use CommonGoals\Domain;
use CommonGoals\EventLogger;
use CommonGoals\RestApi;
use CommonGoals\TemplateLoader;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Displays a goal board and handles public contribution submissions.
 */
final class BoardShortcode
{
    /**
     * Registers shortcode and form handling hooks.
     */
    public function register_hooks(): void
    {
        add_shortcode('common_goals_board', [$this, 'render_shortcode']);
        add_action('admin_post_common_goals_create_contribution', [$this, 'handle_create_contribution']);
        add_action('admin_post_nopriv_common_goals_create_contribution', [$this, 'handle_create_contribution']);
        add_action('admin_post_common_goals_create_response', [$this, 'handle_create_response']);
        add_action('admin_post_nopriv_common_goals_create_response', [$this, 'handle_create_response']);
        add_action('admin_post_common_goals_edit_contribution', [$this, 'handle_edit_contribution']);
        add_action('admin_post_common_goals_delete_contribution', [$this, 'handle_delete_contribution']);
        add_action('admin_post_common_goals_create_report', [$this, 'handle_create_report']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    /**
     * Registers frontend assets without forcing them onto every page.
     */
    public function register_assets(): void
    {
        wp_register_style(
            'common-goals-board',
            COMMON_GOALS_PLUGIN_URL . 'assets/css/board.css',
            [],
            COMMON_GOALS_VERSION
        );

        wp_register_script(
            'common-goals-board',
            COMMON_GOALS_PLUGIN_URL . 'assets/js/board.js',
            [],
            COMMON_GOALS_VERSION,
            true
        );

        wp_register_script(
            'common-goals-ai',
            COMMON_GOALS_PLUGIN_URL . 'assets/js/ai.js',
            [],
            COMMON_GOALS_VERSION,
            true
        );

        wp_localize_script(
            'common-goals-board',
            'CommonGoalsBoard',
            [
            'restUrl'   => esc_url_raw(rest_url(RestApi::NAMESPACE . '/vote')),
            'bookmarkUrl' => esc_url_raw(rest_url(RestApi::NAMESPACE . '/bookmark')),
            'notifReadUrl' => esc_url_raw(rest_url(RestApi::NAMESPACE . '/notifications/read')),
            'aiBaseUrl' => esc_url_raw(rest_url(\CommonGoals\AI\AiRouter::NAMESPACE . \CommonGoals\AI\AiRouter::ROUTE_PREFIX)),
            'nonce'     => wp_create_nonce('wp_rest'),
                'isLoggedIn'=> is_user_logged_in(),
                'loginUrl'  => wp_login_url(esc_url_raw(home_url(add_query_arg([])))),
                'i18n'      => [
                    'loginToVote'  => __('Please log in to vote.', 'common-goals'),
                    'loginToReply' => __('Please log in to reply.', 'common-goals'),
                    'reply'        => __('Reply', 'common-goals'),
                    'cancel'       => __('Cancel', 'common-goals'),
                    'voteError'    => __('Could not register your vote. Try again.', 'common-goals'),
                    'save'         => __('Save', 'common-goals'),
                    'saved'        => __('Saved', 'common-goals'),
                    'aiLoading'    => __('The assistant is working…', 'common-goals'),
                    'aiError'      => __('The assistant could not respond. Try again.', 'common-goals'),
                    'aiUseDraft'   => __('Use suggestion', 'common-goals'),
                ],
            ]
        );
    }

    /**
     * Renders the public board shortcode.
     *
     * @param array<string, mixed> $attributes Shortcode attributes.
     */
    public function render_shortcode(array $attributes): string
    {
        global $wpdb;

        wp_enqueue_style('common-goals-board');
        wp_enqueue_script('common-goals-board');
        wp_enqueue_script('common-goals-ai');

        $attributes = shortcode_atts(
            [
                'goal_id'      => 0,
                'community_id' => 0,
            ],
            $attributes,
            'common_goals_board'
        );

        $goal_id         = absint($attributes['goal_id']);
        $community_id    = absint($attributes['community_id']);
        $selected_type   = sanitize_key(wp_unslash($_GET['common_goals_type'] ?? ''));
        $selected_status = sanitize_key(wp_unslash($_GET['common_goals_status'] ?? ''));
        $selected_topic  = sanitize_text_field(wp_unslash($_GET['cg_topic'] ?? ''));
        $search_term     = sanitize_text_field(wp_unslash($_GET['cg_search'] ?? ''));
        $sort            = sanitize_key(wp_unslash($_GET['cg_sort'] ?? ''));
        $current_page    = max(1, absint($_GET['cg_page'] ?? 1));
        $notice          = sanitize_key(wp_unslash($_GET['common_goals_notice'] ?? ''));

        if (! in_array($sort, ['hot', 'new', 'top'], true)) {
            $sort = 'hot';
        }

        if ($goal_id > 0) {
            $goal = Domain::get_active_goal($goal_id, $community_id > 0 ? $community_id : null);
        } else {
            $goal = Domain::get_latest_active_goal($community_id > 0 ? $community_id : null);
        }

        if (! $goal) {
            return '<div class="common-goals-board common-goals-board--empty">' . esc_html__('No community goal has been created yet.', 'common-goals') . '</div>';
        }

        $allowed_types    = Domain::allowed_types_for_goal($goal);
        $visible_statuses = Domain::PUBLIC_STATUSES;

        if (! in_array($selected_type, $allowed_types, true)) {
            $selected_type = '';
        }

        if (! in_array($selected_status, $visible_statuses, true)) {
            $selected_status = '';
        }

        $contributions_table = Database::contributions_table();
        $responses_table     = Database::responses_table();
        $per_page            = 30;

        $where_clauses    = ['goal_id = %d'];
        $query_parameters = [(int) $goal->id];
        $status_placeholders = implode(',', array_fill(0, count($visible_statuses), '%s'));
        $where_clauses[]  = "status IN ({$status_placeholders})";

        foreach ($visible_statuses as $status) {
            $query_parameters[] = $status;
        }

        if ($selected_type !== '') {
            $where_clauses[]    = 'type = %s';
            $query_parameters[] = $selected_type;
        }

        if ($selected_status !== '') {
            $where_clauses[]    = 'status = %s';
            $query_parameters[] = $selected_status;
        }

        if ($selected_topic !== '') {
            $where_clauses[]    = 'topic = %s';
            $query_parameters[] = $selected_topic;
        }

        if ($search_term !== '') {
            $where_clauses[]    = '(title LIKE %s OR body LIKE %s)';
            $like               = '%' . $wpdb->esc_like($search_term) . '%';
            $query_parameters[] = $like;
            $query_parameters[] = $like;
        }

        $where_sql = implode(' AND ', $where_clauses);

        $total_contributions = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$contributions_table} WHERE {$where_sql}", ...$query_parameters));
        $total_pages         = $total_contributions > 0 ? (int) ceil($total_contributions / $per_page) : 1;

        if ($current_page > $total_pages) {
            $current_page = $total_pages;
        }

        $offset       = ($current_page - 1) * $per_page;
        $query_params = array_merge($query_parameters, [$per_page, $offset]);

        $order_by = $this->sort_expression($sort);

        $sql           = "SELECT * FROM {$contributions_table} WHERE {$where_sql} ORDER BY {$order_by} LIMIT %d OFFSET %d";
        $contributions = $wpdb->get_results($wpdb->prepare($sql, ...$query_params));

        $responses_by_contribution_id = [];
        $contribution_ids             = array_map('absint', wp_list_pluck($contributions, 'id'));

        if (! empty($contribution_ids)) {
            $contribution_ids_sql = implode(',', $contribution_ids);
            $responses            = $wpdb->get_results("SELECT * FROM {$responses_table} WHERE contribution_id IN ({$contribution_ids_sql}) AND status = 'published' ORDER BY score DESC, created_at ASC LIMIT 300");

            foreach ($responses as $response) {
                $contribution_id = (int) $response->contribution_id;

                if (! isset($responses_by_contribution_id[$contribution_id])) {
                    $responses_by_contribution_id[$contribution_id] = [];
                }

                $responses_by_contribution_id[$contribution_id][] = $response;
            }
        }

        $author_names    = $this->load_author_names($contributions);
        $current_notice  = $this->get_notice_message($notice);
        $contribution_votes = Domain::get_user_votes('contribution', $contribution_ids);

        $response_ids = [];
        foreach ($responses_by_contribution_id as $list) {
            foreach ($list as $r) {
                $response_ids[] = (int) $r->id;
            }
        }
        $response_votes = Domain::get_user_votes('response', $response_ids);
        $bookmarked_ids = Domain::get_bookmarked_ids($contribution_ids);

        $notifications      = \CommonGoals\InAppNotifications::for_user(get_current_user_id(), 10);
        $notifications_html = $this->render_notifications_html($notifications);
        $unread_count       = \CommonGoals\InAppNotifications::unread_count(get_current_user_id());

        ob_start();
        include TemplateLoader::locate('board.php');

        return (string) ob_get_clean();
    }

    /**
     * Builds the HTML list of notifications for the bell dropdown.
     *
     * @param array<int, object> $notifications Notification rows.
     */
    private function render_notifications_html(array $notifications): string
    {
        if ($notifications === []) {
            return '<p class="common-goals-notif-empty">' . esc_html__('No notifications yet.', 'common-goals') . '</p>';
        }

        $html = '<ul class="common-goals-notif-list">';

        foreach ($notifications as $n) {
            $unread = (int) $n->is_read === 0 ? ' common-goals-notif-item--unread' : '';
            $html .= '<li class="common-goals-notif-item' . $unread . '" data-id="' . esc_attr((string) $n->id) . '">';
            $html .= '<span class="common-goals-notif-item__summary">' . esc_html($n->summary) . '</span>';
            $html .= '<span class="common-goals-notif-item__time">' . esc_html(mysql2date(get_option('date_format'), $n->created_at)) . '</span>';
            $html .= '</li>';
        }

        $html .= '</ul>';

        return $html;
    }

    /**
     * Returns the ORDER BY SQL fragment for the requested sort mode.
     *
     * The "hot" ranking blends score, views and recency so that freshly
     * contributed threads with engagement rise while older ones decay,
     * mirroring the default feed ordering of community platforms.
     */
    private function sort_expression(string $sort): string
    {
        // Pinned threads always float to the top, regardless of sort mode.
        $sticky_first = 'is_sticky DESC, ';

        if ($sort === 'top') {
            return $sticky_first . 'score DESC, created_at DESC';
        }

        if ($sort === 'new') {
            return $sticky_first . 'created_at DESC';
        }

        // hot: engagement weighted (score + views on a log scale), with a gentle
        // age decay (~1 point every 8 hours) so the feed favors recent activity
        // without instantly burying popular threads from a few days ago.
        return $sticky_first . '((GREATEST(score, 0) * 3) + (LOG10(views + 1) * 6) - (TIMESTAMPDIFF(HOUR, created_at, NOW()) / 8)) DESC, created_at DESC';
    }

    /**
     * Loads display names for contribution authors in a single batch.
     *
     * @param array<int, object> $contributions Contribution rows.
     * @return array<int, string> Map of user_id to display name.
     */
    private function load_author_names(array $contributions): array
    {
        $user_ids = array_unique(array_filter(array_map('intval', wp_list_pluck($contributions, 'user_id'))));
        $names    = [];

        foreach ($user_ids as $uid) {
            if ($uid <= 0) {
                continue;
            }

            $user = get_userdata($uid);
            $names[$uid] = $user ? $user->display_name : __('Unknown user', 'common-goals');
        }

        return $names;
    }

    /**
     * Handles a user report submitted from the public board.
     */
    public function handle_create_report(): void
    {
        check_admin_referer('common_goals_create_report');

        $redirect_url = wp_get_referer() ?: home_url('/');
        $object_type  = sanitize_key(wp_unslash($_POST['object_type'] ?? ''));
        $object_id    = absint($_POST['object_id'] ?? 0);
        $reason       = sanitize_key(wp_unslash($_POST['report_reason'] ?? ''));
        $detail       = sanitize_text_field(wp_unslash($_POST['report_detail'] ?? ''));

        if (! is_user_logged_in()) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'permission_denied', $redirect_url));
            exit;
        }

        if (Domain::honeypot_triggered()) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'report_created', $redirect_url));
            exit;
        }

        if (! Domain::check_rate_limit('report')) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'rate_limited', $redirect_url));
            exit;
        }

        if (mb_strlen($detail) > 500) {
            $detail = mb_substr($detail, 0, 500);
        }

        $report_id = Domain::create_report($object_type, $object_id, $reason, $detail);

        $notice = $report_id > 0 ? 'report_created' : 'invalid_report';
        wp_safe_redirect(add_query_arg('common_goals_notice', $notice, $redirect_url));
        exit;
    }

    /**
     * Saves a contribution submitted from the public board.
     */
    public function handle_create_contribution(): void
    {
        check_admin_referer('common_goals_create_contribution');

        global $wpdb;

        $redirect_url = wp_get_referer() ?: home_url('/');
        $goal_id      = absint($_POST['goal_id'] ?? 0);
        $type         = sanitize_key(wp_unslash($_POST['contribution_type'] ?? 'question'));
        $topic        = sanitize_text_field(wp_unslash($_POST['contribution_topic'] ?? ''));
        $title        = sanitize_text_field(wp_unslash($_POST['contribution_title'] ?? ''));
        $body         = wp_kses_post(wp_unslash($_POST['contribution_body'] ?? ''));

        if (Domain::honeypot_triggered()) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'contribution_created', $redirect_url));
            exit;
        }

        $is_guest = ! is_user_logged_in();

        if ($is_guest && ! (bool) get_option('common_goals_allow_guest_posting', 1)) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'guest_posting_disabled', $redirect_url));
            exit;
        }

        $goal = Domain::get_active_goal($goal_id);

        if (! $goal) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_goal', $redirect_url));
            exit;
        }

        $allowed_types = Domain::allowed_types_for_goal($goal);

        if (! in_array($type, $allowed_types, true)) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_type', $redirect_url));
            exit;
        }

        if (mb_strlen($title) > Domain::MAX_TITLE_LENGTH || mb_strlen($topic) > Domain::MAX_TOPIC_LENGTH || mb_strlen($body) > Domain::MAX_BODY_LENGTH) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'too_long', $redirect_url));
            exit;
        }

        if ($title === '' || $body === '') {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_contribution', $redirect_url));
            exit;
        }

        if (! Domain::check_rate_limit('contribution')) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'rate_limited', $redirect_url));
            exit;
        }

        if (Domain::is_spam($title . ' ' . $body, 'contribution')) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'spam_detected', $redirect_url));
            exit;
        }

        $now                 = current_time('mysql');
        $contributions_table = Database::contributions_table();
        $status              = $is_guest ? 'pending' : 'open';

        $inserted = $wpdb->insert(
            $contributions_table,
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
            wp_safe_redirect(add_query_arg('common_goals_notice', 'db_error', $redirect_url));
            exit;
        }

        $contribution_id = (int) $wpdb->insert_id;

        EventLogger::log('contribution', $contribution_id, 'contribution.created', [
            'goal_id'      => $goal_id,
            'community_id' => (int) $goal->community_id,
            'type'         => $type,
        ]);

        do_action('common_goals_contribution_created', $contribution_id, [
            'goal_id' => $goal_id,
            'community_id' => (int) $goal->community_id,
            'status'  => $status,
            'type'    => $type,
        ]);

        $notice = $is_guest ? 'contribution_pending' : 'contribution_created';
        wp_safe_redirect(add_query_arg('common_goals_notice', $notice, $redirect_url));
        exit;
    }

    /**
     * Saves a response submitted from a contribution card.
     */
    public function handle_create_response(): void
    {
        check_admin_referer('common_goals_create_response');

        global $wpdb;

        $redirect_url    = wp_get_referer() ?: home_url('/');
        $contribution_id = absint($_POST['contribution_id'] ?? 0);
        $parent_id       = absint($_POST['parent_id'] ?? 0);
        $body            = wp_kses_post(wp_unslash($_POST['response_body'] ?? ''));

        if (Domain::honeypot_triggered()) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'response_created', $redirect_url));
            exit;
        }

        $is_guest = ! is_user_logged_in();

        if ($is_guest && ! (bool) get_option('common_goals_allow_guest_posting', 1)) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'guest_posting_disabled', $redirect_url));
            exit;
        }

        $contribution = Domain::get_visible_contribution($contribution_id);

        if (! $contribution) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_contribution', $redirect_url));
            exit;
        }

        if (mb_strlen($body) > Domain::MAX_RESPONSE_LENGTH) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'too_long', $redirect_url));
            exit;
        }

        if ($body === '') {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_response', $redirect_url));
            exit;
        }

        if ($parent_id > 0) {
            $parent = Domain::get_visible_response($parent_id);

            if (! $parent || (int) $parent->contribution_id !== $contribution_id) {
                $parent_id = 0;
            }
        }

        if (! Domain::check_rate_limit('response')) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'rate_limited', $redirect_url));
            exit;
        }

        $now             = current_time('mysql');
        $responses_table = Database::responses_table();
        $status          = $is_guest ? 'pending' : 'published';

        $inserted = $wpdb->insert(
            $responses_table,
            [
                'contribution_id' => $contribution_id,
                'parent_id'       => $parent_id,
                'user_id'         => get_current_user_id(),
                'body'            => $body,
                'status'          => $status,
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            ['%d', '%d', '%d', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'db_error', $redirect_url));
            exit;
        }

        $response_id = (int) $wpdb->insert_id;

        EventLogger::log('response', $response_id, 'response.created', [
            'contribution_id' => $contribution_id,
            'community_id'    => self::community_id_for_goal((int) $contribution->goal_id),
        ]);

        do_action('common_goals_response_created', $response_id, [
            'contribution_id' => $contribution_id,
            'status'          => $status,
        ]);

        $notice = $is_guest ? 'response_pending' : 'response_created';
        wp_safe_redirect(add_query_arg('common_goals_notice', $notice, $redirect_url));
        exit;
    }

    /**
     * Returns a human-readable message for a notice code.
     *
     * @return string Empty string if the code is not recognized.
     */
    private function get_notice_message(string $code): string
    {
        $messages = [
            'contribution_created'  => __('Your contribution has been published.', 'common-goals'),
            'contribution_pending'  => __('Your contribution has been submitted and is awaiting moderation.', 'common-goals'),
            'response_created'      => __('Your response has been published.', 'common-goals'),
            'response_pending'      => __('Your response has been submitted and is awaiting moderation.', 'common-goals'),
            'invalid_goal'          => __('The selected goal is not available.', 'common-goals'),
            'invalid_type'          => __('That contribution type is not allowed for this goal.', 'common-goals'),
            'invalid_contribution'  => __('That contribution is no longer available.', 'common-goals'),
            'invalid_response'      => __('Please write a response before submitting.', 'common-goals'),
            'too_long'              => __('Your submission exceeds the maximum allowed length.', 'common-goals'),
            'rate_limited'          => __('You are submitting too quickly. Please wait a few minutes.', 'common-goals'),
            'guest_posting_disabled'=> __('Guest posting is disabled. Please log in to participate.', 'common-goals'),
            'spam_detected'         => __('Your submission was flagged as spam and has not been published.', 'common-goals'),
            'db_error'              => __('Something went wrong saving your submission. Please try again.', 'common-goals'),
            'contribution_updated'  => __('Your contribution has been updated.', 'common-goals'),
            'contribution_deleted'  => __('Your contribution has been deleted.', 'common-goals'),
            'permission_denied'     => __('You do not have permission to do that.', 'common-goals'),
            'report_created'        => __('Thanks. Your report has been sent to the moderators.', 'common-goals'),
            'invalid_report'        => __('That report could not be submitted.', 'common-goals'),
        ];

        return $messages[$code] ?? '';
    }

    /**
     * Handles editing of a contribution by its original author.
     */
    public function handle_edit_contribution(): void
    {
        check_admin_referer('common_goals_edit_contribution');

        global $wpdb;

        $redirect_url    = wp_get_referer() ?: home_url('/');
        $user_id         = get_current_user_id();
        $contribution_id = absint($_POST['contribution_id'] ?? 0);
        $title           = sanitize_text_field(wp_unslash($_POST['contribution_title'] ?? ''));
        $body            = wp_kses_post(wp_unslash($_POST['contribution_body'] ?? ''));

        if ($user_id <= 0) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'permission_denied', $redirect_url));
            exit;
        }

        $contributions_table = Database::contributions_table();
        $contribution        = Domain::get_contribution($contribution_id);

        if (! $contribution || (int) $contribution->user_id !== $user_id) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'permission_denied', $redirect_url));
            exit;
        }

        if ($title === '' || $body === '' || mb_strlen($title) > Domain::MAX_TITLE_LENGTH || mb_strlen($body) > Domain::MAX_BODY_LENGTH) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'invalid_contribution', $redirect_url));
            exit;
        }

        $updated = $wpdb->update(
            $contributions_table,
            [
                'title'      => $title,
                'body'       => $body,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $contribution_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'db_error', $redirect_url));
            exit;
        }

        EventLogger::log('contribution', $contribution_id, 'contribution.updated', [
            'user_id'      => $user_id,
            'community_id' => self::community_id_for_goal((int) $contribution->goal_id),
        ]);

        wp_safe_redirect(add_query_arg('common_goals_notice', 'contribution_updated', $redirect_url));
        exit;
    }

    /**
     * Handles deletion of a contribution by its original author.
     */
    public function handle_delete_contribution(): void
    {
        check_admin_referer('common_goals_delete_contribution');

        global $wpdb;

        $redirect_url    = wp_get_referer() ?: home_url('/');
        $user_id         = get_current_user_id();
        $contribution_id = absint($_POST['contribution_id'] ?? 0);

        if ($user_id <= 0) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'permission_denied', $redirect_url));
            exit;
        }

        $contribution = Domain::get_contribution($contribution_id);

        if (! $contribution || (int) $contribution->user_id !== $user_id) {
            wp_safe_redirect(add_query_arg('common_goals_notice', 'permission_denied', $redirect_url));
            exit;
        }

        $contributions_table = Database::contributions_table();
        $responses_table     = Database::responses_table();

        $wpdb->query('START TRANSACTION');

        $responses_deleted = $wpdb->delete($responses_table, ['contribution_id' => $contribution_id], ['%d']);

        if ($responses_deleted === false) {
            $wpdb->query('ROLLBACK');
            wp_safe_redirect(add_query_arg('common_goals_notice', 'db_error', $redirect_url));
            exit;
        }

        $deleted = $wpdb->delete($contributions_table, ['id' => $contribution_id], ['%d']);

        if ($deleted === false) {
            $wpdb->query('ROLLBACK');
            wp_safe_redirect(add_query_arg('common_goals_notice', 'db_error', $redirect_url));
            exit;
        }

        $wpdb->query('COMMIT');

        EventLogger::log('contribution', $contribution_id, 'contribution.deleted', [
            'user_id'      => $user_id,
            'community_id' => self::community_id_for_goal((int) $contribution->goal_id),
        ]);

        wp_safe_redirect(add_query_arg('common_goals_notice', 'contribution_deleted', $redirect_url));
        exit;
    }

    /**
     * Finds the community for a goal ID for audit metadata.
     */
    private static function community_id_for_goal(int $goal_id): int
    {
        global $wpdb;

        if ($goal_id <= 0) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare('SELECT community_id FROM ' . Database::goals_table() . ' WHERE id = %d', $goal_id));
    }
}
