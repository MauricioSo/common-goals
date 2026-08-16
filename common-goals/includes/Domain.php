<?php
/**
 * Central domain knowledge: statuses, types, limits and validation helpers.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Single source of truth for contribution statuses, types, field limits
 * and the validation queries that protect public write endpoints.
 */
final class Domain
{
    public const CONTRIBUTION_TYPES      = ['question', 'problem', 'experience', 'resource'];
    public const CONTRIBUTION_STATUSES   = ['pending', 'open', 'in_progress', 'resolved', 'spam', 'hidden'];
    public const PUBLIC_STATUSES         = ['open', 'in_progress', 'resolved'];
    public const GUIDE_STATUSES          = ['draft', 'review', 'published', 'hidden'];
    public const GOAL_STATUSES           = ['active', 'inactive'];
    public const COMMUNITY_ROLES         = ['admin', 'moderator', 'member'];

    public const MAX_TITLE_LENGTH        = 190;
    public const MAX_TOPIC_LENGTH        = 120;

    /**
     * Formats a large count using compact suffixes (1.2k, 3.4M) for display.
     */
    public static function format_count(int $count): string
    {
        if ($count >= 1000000) {
            return number_format_i18n($count / 1000000, 1) . 'M';
        }

        if ($count >= 1000) {
            return number_format_i18n($count / 1000, 1) . 'k';
        }

        return (string) $count;
    }

    /**
     * Renders community content as Markdown with @mentions linked to profiles.
     */
    public static function render_content(string $text): string
    {
        return InAppNotifications::link_mentions(Markdown::render($text));
    }
    public const MAX_BODY_LENGTH         = 10000;
    public const MAX_RESPONSE_LENGTH     = 5000;
    public const MAX_GOAL_TITLE_LENGTH   = 190;

    public const RATE_LIMIT_WINDOW       = 300;
    public const RATE_LIMIT_MAX          = 5;

    /**
     * Returns the list of contribution types allowed by a specific goal.
     *
     * @param object|null $goal Goal row from the database.
     * @return string[]
     */
    public static function allowed_types_for_goal(?object $goal): array
    {
        if (! $goal || empty($goal->allowed_contribution_types)) {
            return self::CONTRIBUTION_TYPES;
        }

        $decoded = json_decode($goal->allowed_contribution_types, true);

        if (! is_array($decoded) || $decoded === []) {
            return self::CONTRIBUTION_TYPES;
        }

        $filtered = array_filter($decoded, static fn ($type) => in_array($type, self::CONTRIBUTION_TYPES, true));

        $result = $filtered !== [] ? array_values($filtered) : self::CONTRIBUTION_TYPES;

        return (array) apply_filters('common_goals_allowed_types', $result, $goal);
    }

    /**
     * Returns the default community ID (1) or the first active community.
     */
    public static function get_default_community_id(): int
    {
        global $wpdb;

        $table = Database::communities_table();

        return (int) $wpdb->get_var("SELECT id FROM {$table} WHERE status = 'active' ORDER BY id ASC LIMIT 1") ?: 1;
    }

    /**
     * Fetches an active community by ID.
     */
    public static function get_community(int $community_id): ?object
    {
        global $wpdb;

        if ($community_id <= 0) {
            return null;
        }

        $table = Database::communities_table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND status = 'active'", $community_id)
        ) ?: null;
    }

    /**
     * Returns a user's role in a community, or null if they are not a member.
     */
    public static function get_user_community_role(int $user_id, int $community_id): ?string
    {
        global $wpdb;

        if ($user_id <= 0 || $community_id <= 0) {
            return null;
        }

        $table = Database::community_members_table();
        $role  = $wpdb->get_var($wpdb->prepare("SELECT role FROM {$table} WHERE user_id = %d AND community_id = %d", $user_id, $community_id));

        return is_string($role) && in_array($role, self::COMMUNITY_ROLES, true) ? $role : null;
    }

    /**
     * Returns community IDs where the current user has one of the requested roles.
     *
     * @param string[] $roles Accepted community roles.
     * @return int[]
     */
    public static function current_user_community_ids(array $roles): array
    {
        global $wpdb;

        $user_id = get_current_user_id();

        if ($user_id <= 0 || $roles === []) {
            return [];
        }

        $roles = array_values(array_intersect($roles, self::COMMUNITY_ROLES));

        if ($roles === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($roles), '%s'));
        $table        = Database::community_members_table();

        return array_map(
            'intval',
            $wpdb->get_col($wpdb->prepare("SELECT community_id FROM {$table} WHERE user_id = %d AND role IN ({$placeholders})", $user_id, ...$roles))
        );
    }

    /**
     * Whether the current user can manage a specific community.
     */
    public static function current_user_can_manage_community(int $community_id): bool
    {
        if (current_user_can(Capabilities::MANAGE)) {
            return true;
        }

        return self::get_user_community_role(get_current_user_id(), $community_id) === 'admin';
    }

    /**
     * Whether the current user can moderate a specific community.
     */
    public static function current_user_can_moderate_community(int $community_id): bool
    {
        if (current_user_can(Capabilities::MODERATE)) {
            return true;
        }

        return in_array(self::get_user_community_role(get_current_user_id(), $community_id), ['admin', 'moderator'], true);
    }

    /**
     * Whether the current user can publish guides for a specific community.
     */
    public static function current_user_can_publish_guides_for_community(int $community_id): bool
    {
        if (current_user_can(Capabilities::PUBLISH_GUIDES)) {
            return true;
        }

        return in_array(self::get_user_community_role(get_current_user_id(), $community_id), ['admin', 'moderator'], true);
    }

    /**
     * Whether the current user can view events for a specific community.
     */
    public static function current_user_can_view_events_for_community(int $community_id): bool
    {
        if (current_user_can(Capabilities::VIEW_EVENTS)) {
            return true;
        }

        return in_array(self::get_user_community_role(get_current_user_id(), $community_id), ['admin', 'moderator'], true);
    }

    /**
     * Access helpers for admin menu visibility. A user may reach an admin area
     * through a global capability or through a community-scoped role. These
     * helpers are used to register menus only for users who can act on them,
     * avoiding a leaky menu for accounts without any community assignment.
     */
    public static function current_user_can_access_goal_management(): bool
    {
        if (current_user_can(Capabilities::MANAGE)) {
            return true;
        }

        return self::current_user_community_ids(['admin']) !== [];
    }

    public static function current_user_can_access_moderation(): bool
    {
        if (current_user_can(Capabilities::MODERATE)) {
            return true;
        }

        return self::current_user_community_ids(['admin', 'moderator']) !== [];
    }

    public static function current_user_can_access_guides(): bool
    {
        if (current_user_can(Capabilities::PUBLISH_GUIDES)) {
            return true;
        }

        return self::current_user_community_ids(['admin', 'moderator']) !== [];
    }

    public static function current_user_can_access_events(): bool
    {
        if (current_user_can(Capabilities::VIEW_EVENTS)) {
            return true;
        }

        return self::current_user_community_ids(['admin', 'moderator']) !== [];
    }

    public static function current_user_can_access_communities(): bool
    {
        if (current_user_can(Capabilities::MANAGE)) {
            return true;
        }

        return self::current_user_community_ids(['admin']) !== [];
    }

    public static function current_user_can_access_any_admin_area(): bool
    {
        return self::current_user_can_access_goal_management()
            || self::current_user_can_access_moderation()
            || self::current_user_can_access_guides()
            || self::current_user_can_access_events()
            || self::current_user_can_access_communities();
    }

    /**
     * Returns a SQL fragment for limiting goals by allowed communities.
     *
     * @param string $column Fully-qualified community ID column.
     * @param int[]  $ids    Community IDs.
     */
    public static function community_scope_sql(string $column, array $ids): string
    {
        $ids = array_values(array_filter(array_map('absint', $ids)));

        if ($ids === []) {
            return '1 = 0';
        }

        return $column . ' IN (' . implode(',', $ids) . ')';
    }

    /**
     * Fetches a goal and confirms it exists and is active.
     *
     * @param int      $goal_id      Goal ID.
     * @param int|null $community_id Optional community scope.
     */
    public static function get_active_goal(int $goal_id, ?int $community_id = null): ?object
    {
        global $wpdb;

        if ($goal_id <= 0) {
            return null;
        }

        $table = Database::goals_table();

        if ($community_id !== null && $community_id > 0) {
            return $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND community_id = %d AND status = 'active'", $goal_id, $community_id)
            ) ?: null;
        }

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND status = 'active'", $goal_id)
        ) ?: null;
    }

    /**
     * Fetches the most recently created active goal, optionally within a community.
     *
     * @param int|null $community_id Optional community scope.
     */
    public static function get_latest_active_goal(?int $community_id = null): ?object
    {
        global $wpdb;

        $table = Database::goals_table();

        if ($community_id !== null && $community_id > 0) {
            return $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE community_id = %d AND status = 'active' ORDER BY created_at DESC LIMIT 1", $community_id)
            ) ?: null;
        }

        return $wpdb->get_row("SELECT * FROM {$table} WHERE status = 'active' ORDER BY created_at DESC LIMIT 1") ?: null;
    }

    /**
     * Fetches a contribution only if it exists and has a publicly visible status.
     */
    public static function get_visible_contribution(int $contribution_id): ?object
    {
        global $wpdb;

        if ($contribution_id <= 0) {
            return null;
        }

        $table    = Database::contributions_table();
        $statuses = self::PUBLIC_STATUSES;
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND status IN ({$placeholders})", $contribution_id, ...$statuses)
        ) ?: null;
    }

    /**
     * Fetches any contribution regardless of status (for admin operations).
     */
    public static function get_contribution(int $contribution_id): ?object
    {
        global $wpdb;

        if ($contribution_id <= 0) {
            return null;
        }

        $table = Database::contributions_table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $contribution_id)
        ) ?: null;
    }

    /**
     * Pins or unpins a contribution. Only callable after the caller has checked
     * moderation permissions for the contribution's community.
     *
     * @return bool True on success.
     */
    public static function set_sticky(int $contribution_id, bool $sticky): bool
    {
        global $wpdb;

        if ($contribution_id <= 0) {
            return false;
        }

        $table = Database::contributions_table();

        $updated = $wpdb->update(
            $table,
            [
                'is_sticky'  => $sticky ? 1 : 0,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $contribution_id],
            ['%d', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return false;
        }

        EventLogger::log('contribution', $contribution_id, 'contribution.sticky_changed', [
            'is_sticky' => $sticky ? 1 : 0,
        ]);

        return true;
    }

    /**
     * Fetches a response only if it exists and is publicly published.
     */
    public static function get_visible_response(int $response_id): ?object
    {
        global $wpdb;

        if ($response_id <= 0) {
            return null;
        }

        $table = Database::responses_table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND status = 'published'", $response_id)
        ) ?: null;
    }

    /**
     * Checks whether a submitter has exceeded the rate limit for an action.
     *
     * The limit is configurable via the `common_goals_rate_limit_max` option
     * (registered on the settings page) and falls back to the RATE_LIMIT_MAX
     * constant when the option is absent or invalid.
     */
    public static function check_rate_limit(string $action): bool
    {
        $identifier = self::submitter_identifier();
        $key        = 'cg_rate_' . $action . '_' . $identifier;
        $count      = (int) get_transient($key);
        $max        = self::rate_limit_max();

        if ($count >= $max) {
            return false;
        }

        $count++;
        set_transient($key, $count, self::RATE_LIMIT_WINDOW);

        return true;
    }

    /**
     * Returns the configured maximum submissions per window.
     */
    public static function rate_limit_max(): int
    {
        $configured = absint(get_option('common_goals_rate_limit_max', self::RATE_LIMIT_MAX));

        if ($configured <= 0) {
            return self::RATE_LIMIT_MAX;
        }

        return $configured;
    }

    /**
     * Returns a stable identifier for the current submitter.
     */
    private static function submitter_identifier(): string
    {
        $user_id = get_current_user_id();

        if ($user_id > 0) {
            return 'u' . $user_id;
        }

        return 'i' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    /**
     * Returns true if the honeypot field was filled (bot signal).
     */
    public static function honeypot_triggered(): bool
    {
        if (! (bool) get_option('common_goals_honeypot_enabled', 1)) {
            return false;
        }

        $value = sanitize_text_field(wp_unslash($_POST['cg_website'] ?? ''));

        return $value !== '';
    }

    /**
     * Runs extensible anti-spam checks on submitted content.
     *
     * The plugin does not ship with a hard-coded spam provider. Third-party
     * integrations (Akismet, reCAPTCHA, TypeMatc, custom heuristics) hook into
     * the `common_goals_spam_check` filter to evaluate the content. The filter
     * receives the content and its type, and must return a boolean.
     *
     * @param string $content The text content to check.
     * @param string $type    The content type (contribution or response).
     * @return bool True if the content is likely spam.
     */
    public static function is_spam(string $content, string $type = 'contribution'): bool
    {
        $is_spam = strlen($content) > 0 && self::contains_excessive_links($content);

        return (bool) apply_filters('common_goals_spam_check', $is_spam, $content, $type);
    }

    /**
     * Lightweight heuristic: flags content with an unusually high number of
     * links, a common signal of automated spam. Third-party spam providers
     * hooked into `common_goals_spam_check` can override or extend this.
     */
    private static function contains_excessive_links(string $content): bool
    {
        $link_count = preg_match_all('#https?://#i', $content);

        return $link_count >= 5;
    }

    /**
     * Validates that a status transition is allowed by the domain rules.
     *
     * @param string $from Current status.
     * @param string $to   Requested next status.
     */
    public static function is_valid_transition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        $transitions = [
            'pending'     => ['open', 'spam', 'hidden'],
            'open'        => ['in_progress', 'resolved', 'spam', 'hidden'],
            'in_progress' => ['open', 'resolved', 'spam', 'hidden'],
            'resolved'    => ['open', 'in_progress', 'hidden'],
            'spam'        => ['pending', 'open', 'hidden'],
            'hidden'      => ['pending', 'open', 'in_progress', 'spam'],
        ];

        return in_array($to, $transitions[$from] ?? [], true);
    }

    /**
     * Returns the list of response statuses valid for moderation.
     *
     * @return string[]
     */
    public static function response_statuses(): array
    {
        return ['pending', 'published', 'spam', 'hidden'];
    }

    /**
     * Returns publicly visible response statuses (positive list).
     *
     * @return string[]
     */
    public static function public_response_statuses(): array
    {
        return ['published'];
    }

    /**
     * Object types that accept votes.
     */
    public const VOTE_OBJECT_TYPES = ['contribution', 'response'];

    /**
     * Accepted reasons for a user report.
     */
    public const REPORT_REASONS = ['spam', 'harassment', 'off_topic', 'other'];

    /**
     * Accepted statuses for a report.
     */
    public const REPORT_STATUSES = ['pending', 'resolved', 'dismissed'];

    /**
     * Stores a user report about a contribution or response.
     *
     * @return int Inserted report ID, or 0 on failure.
     */
    public static function create_report(string $object_type, int $object_id, string $reason, string $detail): int
    {
        global $wpdb;

        $reporter_id = get_current_user_id();

        if ($reporter_id <= 0 || ! in_array($object_type, self::VOTE_OBJECT_TYPES, true) || $object_id <= 0) {
            return 0;
        }

        if (! in_array($reason, self::REPORT_REASONS, true)) {
            return 0;
        }

        $table = Database::reports_table();
        $now   = current_time('mysql');

        $inserted = $wpdb->insert(
            $table,
            [
                'object_type' => $object_type,
                'object_id'   => $object_id,
                'reporter_id' => $reporter_id,
                'reason'      => $reason,
                'detail'      => $detail,
                'status'      => 'pending',
                'created_at'  => $now,
            ],
            ['%s', '%d', '%d', '%s', '%s', '%s', '%s']
        );

        return $inserted !== false ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Casts or toggles a vote by the current user on a contribution or response.
     *
     * Vote value is normalized to +1 or -1. Voting the same value twice removes
     * the vote (toggle off). Voting the opposite value flips it. The denormalized
     * score column on the target row is kept in sync inside a transaction so the
     * public list can be ordered by score without recounting.
     *
     * @param string $object_type 'contribution' or 'response'.
     * @param int    $object_id   Target row ID.
     * @param int    $value       Requested vote (+1 up, -1 down).
     * @return array{score: int, user_vote: int} New total score and the user's
     *                                            resulting vote (-1, 0 or 1).
     */
    public static function cast_vote(string $object_type, int $object_id, int $value): array
    {
        global $wpdb;

        $user_id = get_current_user_id();
        $value   = $value >= 0 ? 1 : -1;

        if ($user_id <= 0 || ! in_array($object_type, self::VOTE_OBJECT_TYPES, true) || $object_id <= 0) {
            return ['score' => 0, 'user_vote' => 0];
        }

        $target_table = $object_type === 'response' ? Database::responses_table() : Database::contributions_table();
        $votes_table  = Database::votes_table();

        $wpdb->query('START TRANSACTION');

        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT id, value FROM {$votes_table} WHERE user_id = %d AND object_type = %s AND object_id = %d FOR UPDATE", $user_id, $object_type, $object_id)
        );

        if (! $existing) {
            $inserted = $wpdb->insert(
                $votes_table,
                ['user_id' => $user_id, 'object_type' => $object_type, 'object_id' => $object_id, 'value' => $value, 'created_at' => current_time('mysql')],
                ['%d', '%s', '%d', '%d', '%s']
            );

            if ($inserted !== false) {
                $wpdb->query($wpdb->prepare("UPDATE {$target_table} SET score = score + %d WHERE id = %d", $value, $object_id));
            }

            $user_vote = $inserted !== false ? $value : 0;
        } elseif ((int) $existing->value === $value) {
            $deleted = $wpdb->delete($votes_table, ['id' => $existing->id], ['%d']);

            if ($deleted !== false) {
                $wpdb->query($wpdb->prepare("UPDATE {$target_table} SET score = score - %d WHERE id = %d", $value, $object_id));
            }

            $user_vote = 0;
        } else {
            $updated = $wpdb->update($votes_table, ['value' => $value], ['id' => $existing->id], ['%d'], ['%d']);

            if ($updated !== false) {
                $diff = $value * 2;
                $wpdb->query($wpdb->prepare("UPDATE {$target_table} SET score = score + %d WHERE id = %d", $diff, $object_id));
            }

            $user_vote = $value;
        }

        $wpdb->query('COMMIT');

        $new_score = (int) $wpdb->get_var($wpdb->prepare("SELECT score FROM {$target_table} WHERE id = %d", $object_id));

        EventLogger::log($object_type, $object_id, $object_type . '.voted', [
            'user_id' => $user_id,
            'value'   => $user_vote,
            'score'   => $new_score,
        ]);

        return ['score' => $new_score, 'user_vote' => $user_vote];
    }

    /**
     * Returns the current user's vote for each object in a set.
     *
     * @param string   $object_type 'contribution' or 'response'.
     * @param int[]    $object_ids  Target IDs.
     * @return array<int, int> Map of object_id => vote value (-1, 0 or 1).
     */
    public static function get_user_votes(string $object_type, array $object_ids): array
    {
        global $wpdb;

        $user_id = get_current_user_id();
        $object_ids = array_values(array_filter(array_map('absint', $object_ids)));

        if ($user_id <= 0 || $object_ids === [] || ! in_array($object_type, self::VOTE_OBJECT_TYPES, true)) {
            return [];
        }

        $votes_table   = Database::votes_table();
        $placeholders  = implode(',', array_fill(0, count($object_ids), '%d'));
        $prepared_args = array_merge([$user_id, $object_type], $object_ids);

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT object_id, value FROM {$votes_table} WHERE user_id = %d AND object_type = %s AND object_id IN ({$placeholders})", ...$prepared_args)
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->object_id] = (int) $row->value;
        }

        return $map;
    }

    /**
     * Toggles a bookmark (saved thread) for the current user.
     *
     * @return bool True if the thread is now bookmarked.
     */
    public static function toggle_bookmark(int $contribution_id): bool
    {
        global $wpdb;

        $user_id = get_current_user_id();

        if ($user_id <= 0 || $contribution_id <= 0) {
            return false;
        }

        $table     = Database::bookmarks_table();
        $existing  = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE user_id = %d AND contribution_id = %d", $user_id, $contribution_id));

        if ($existing) {
            $wpdb->delete($table, ['id' => $existing], ['%d']);

            return false;
        }

        $wpdb->insert(
            $table,
            ['user_id' => $user_id, 'contribution_id' => $contribution_id, 'created_at' => current_time('mysql')],
            ['%d', '%d', '%s']
        );

        return true;
    }

    /**
     * Returns the set of contribution IDs the current user has bookmarked.
     *
     * @param int[] $contribution_ids Optional restriction to a known set.
     * @return array<int, int> Bookmark IDs the user owns (values are the IDs).
     */
    public static function get_bookmarked_ids(array $contribution_ids = []): array
    {
        global $wpdb;

        $user_id = get_current_user_id();

        if ($user_id <= 0) {
            return [];
        }

        $table = Database::bookmarks_table();

        if ($contribution_ids === []) {
            return array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT contribution_id FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT 200", $user_id)));
        }

        $contribution_ids = array_values(array_filter(array_map('absint', $contribution_ids)));

        if ($contribution_ids === []) {
            return [];
        }

        $placeholders  = implode(',', array_fill(0, count($contribution_ids), '%d'));
        $prepared_args = array_merge([$user_id], $contribution_ids);

        return array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT contribution_id FROM {$table} WHERE user_id = %d AND contribution_id IN ({$placeholders})", ...$prepared_args)));
    }
}
