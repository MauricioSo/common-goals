<?php
/**
 * In-app notifications and @mentions.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Stores and surfaces in-app notifications for community events (replies and
 * @mentions) and resolves @mentions to profile links in rendered content.
 */
final class InAppNotifications
{
    public const MENTION_REGEX = '/(^|[^\/\w])@([A-Za-z0-9._-]{2,60})/';

    /**
     * Registers the hooks that generate notifications.
     */
    public static function register_hooks(): void
    {
        add_action('common_goals_response_created', [self::class, 'on_response_created'], 10, 2);
        add_action('common_goals_contribution_created', [self::class, 'on_contribution_created'], 10, 2);
    }

    /**
     * Generates notifications when a response is published.
     *
     * @param int                  $response_id Response ID.
     * @param array<string, mixed> $data        Response metadata.
     */
    public static function on_response_created(int $response_id, array $data): void
    {
        if (($data['status'] ?? '') !== 'published') {
            return;
        }

        global $wpdb;

        $response      = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Database::responses_table() . ' WHERE id = %d', $response_id));
        $contribution  = $response ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Database::contributions_table() . ' WHERE id = %d', (int) $response->contribution_id)) : null;

        if (! $response || ! $contribution) {
            return;
        }

        $actor_id = (int) $response->user_id;
        $notified = [];

        // Notify the thread author (unless they are the actor).
        if ($contribution->user_id > 0 && (int) $contribution->user_id !== $actor_id) {
            self::create(
                (int) $contribution->user_id,
                'thread_reply',
                'contribution',
                (int) $contribution->id,
                $actor_id,
                sprintf(__('%s responded to your thread "%s".', 'common-goals'), self::actor_name($actor_id), $contribution->title)
            );
            $notified[] = (int) $contribution->user_id;
        }

        // Notify the parent response author for nested replies.
        $parent_id = (int) ($response->parent_id ?? 0);
        if ($parent_id > 0) {
            $parent = $wpdb->get_row($wpdb->prepare('SELECT user_id FROM ' . Database::responses_table() . ' WHERE id = %d', $parent_id));

            if ($parent && (int) $parent->user_id > 0 && (int) $parent->user_id !== $actor_id && ! in_array((int) $parent->user_id, $notified, true)) {
                self::create(
                    (int) $parent->user_id,
                    'reply_reply',
                    'response',
                    $response_id,
                    $actor_id,
                    sprintf(__('%s replied to your comment.', 'common-goals'), self::actor_name($actor_id))
                );
                $notified[] = (int) $parent->user_id;
            }
        }

        // @mentions inside the response body.
        foreach (self::extract_mentions($response->body) as $mentioned_id) {
            if ($mentioned_id > 0 && $mentioned_id !== $actor_id && ! in_array($mentioned_id, $notified, true)) {
                self::create(
                    $mentioned_id,
                    'mention',
                    'response',
                    $response_id,
                    $actor_id,
                    sprintf(__('%s mentioned you in a comment.', 'common-goals'), self::actor_name($actor_id))
                );
                $notified[] = $mentioned_id;
            }
        }
    }

    /**
     * Generates mention notifications for a newly created contribution.
     *
     * @param int                  $contribution_id Contribution ID.
     * @param array<string, mixed> $data            Contribution metadata.
     */
    public static function on_contribution_created(int $contribution_id, array $data): void
    {
        if (! in_array($data['status'] ?? '', Domain::PUBLIC_STATUSES, true)) {
            return;
        }

        global $wpdb;

        $contribution = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Database::contributions_table() . ' WHERE id = %d', $contribution_id));

        if (! $contribution) {
            return;
        }

        $actor_id = (int) $contribution->user_id;

        foreach (self::extract_mentions($contribution->body) as $mentioned_id) {
            if ($mentioned_id > 0 && $mentioned_id !== $actor_id) {
                self::create(
                    $mentioned_id,
                    'mention',
                    'contribution',
                    $contribution_id,
                    $actor_id,
                    sprintf(__('%s mentioned you in "%s".', 'common-goals'), self::actor_name($actor_id), $contribution->title)
                );
            }
        }
    }

    /**
     * Inserts a notification for a user.
     */
    public static function create(int $user_id, string $type, string $object_type, int $object_id, int $actor_id, string $summary): int
    {
        global $wpdb;

        if ($user_id <= 0) {
            return 0;
        }

        $inserted = $wpdb->insert(
            Database::notifications_table(),
            [
                'user_id'      => $user_id,
                'type'         => $type,
                'object_type'  => $object_type,
                'object_id'    => $object_id,
                'actor_id'     => $actor_id,
                'summary'      => $summary,
                'is_read'      => 0,
                'created_at'   => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s']
        );

        return $inserted !== false ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Returns the unread notification count for a user.
     */
    public static function unread_count(int $user_id): int
    {
        global $wpdb;

        if ($user_id <= 0) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Database::notifications_table() . ' WHERE user_id = %d AND is_read = 0', $user_id));
    }

    /**
     * Returns recent notifications for a user.
     *
     * @return array<int, object>
     */
    public static function for_user(int $user_id, int $limit = 12): array
    {
        global $wpdb;

        if ($user_id <= 0) {
            return [];
        }

        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Database::notifications_table() . ' WHERE user_id = %d ORDER BY created_at DESC LIMIT %d', $user_id, $limit));
    }

    /**
     * Marks a single notification as read for its owner.
     */
    public static function mark_read(int $notification_id, int $user_id): void
    {
        global $wpdb;

        if ($notification_id <= 0 || $user_id <= 0) {
            return;
        }

        $wpdb->update(Database::notifications_table(), ['is_read' => 1], ['id' => $notification_id, 'user_id' => $user_id], ['%d'], ['%d', '%d']);
    }

    /**
     * Marks all of a user's notifications as read.
     */
    public static function mark_all_read(int $user_id): void
    {
        global $wpdb;

        if ($user_id <= 0) {
            return;
        }

        $wpdb->update(Database::notifications_table(), ['is_read' => 1], ['user_id' => $user_id], ['%d'], ['%d']);
    }

    /**
     * Returns the display name of an actor.
     */
    private static function actor_name(int $actor_id): string
    {
        if ($actor_id <= 0) {
            return __('Someone', 'common-goals');
        }

        $user = get_userdata($actor_id);

        return $user ? $user->display_name : __('Someone', 'common-goals');
    }

    /**
     * Extracts the user IDs of @mentions found in a text.
     *
     * @return int[]
     */
    public static function extract_mentions(string $text): array
    {
        $ids = [];

        if (! preg_match_all(self::MENTION_REGEX, $text, $matches)) {
            return $ids;
        }

        foreach ($matches[2] as $token) {
            $user_id = self::resolve_mention($token);

            if ($user_id > 0) {
                $ids[] = $user_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Resolves a @token to a user ID by nicename or login.
     */
    public static function resolve_mention(string $token): int
    {
        static $cache = [];

        $token = strtolower($token);

        if (isset($cache[$token])) {
            return $cache[$token];
        }

        $user = get_user_by('slug', $token) ?: get_user_by('login', $token);

        $cache[$token] = $user ? (int) $user->ID : 0;

        return $cache[$token];
    }

    /**
     * Converts @mentions in already-rendered HTML into profile links.
     */
    public static function link_mentions(string $html): string
    {
        return preg_replace_callback(self::MENTION_REGEX, static function ($m): string {
            $user_id = self::resolve_mention($m[2]);

            if ($user_id <= 0) {
                return $m[0];
            }

            $url = Frontend\GuideRouter::author_url($user_id);

            return $m[1] . '<a href="' . esc_url($url) . '" class="common-goals-mention">@' . esc_html($m[2]) . '</a>';
        }, $html);
    }
}
