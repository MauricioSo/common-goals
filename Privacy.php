<?php
/**
 * WordPress privacy integration: exporter, eraser and policy text.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Integrates community data with the WordPress personal data tools so that
 * privacy requests include contributions, responses and events.
 */
final class Privacy
{
    /**
     * Registers WordPress privacy hooks.
     */
    public static function register_hooks(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
        add_action('admin_init', [self::class, 'add_privacy_policy_content']);
        add_action('delete_user', [self::class, 'anonymize_user_data'], 10, 2);
    }

    /**
     * Registers the data exporter for community content.
     *
     * @param array<string, mixed> $exporters Existing exporters.
     * @return array<string, mixed>
     */
    public static function register_exporter(array $exporters): array
    {
        $exporters['common-goals'] = [
            'exporter_friendly_name' => __('Common Goals Community Data', 'common-goals'),
            'callback'               => [self::class, 'export_user_data'],
        ];

        return $exporters;
    }

    /**
     * Registers the data eraser for community content.
     *
     * @param array<string, mixed> $erasers Existing erasers.
     * @return array<string, mixed>
     */
    public static function register_eraser(array $erasers): array
    {
        $erasers['common-goals'] = [
            'eraser_friendly_name' => __('Common Goals Community Data', 'common-goals'),
            'callback'             => [self::class, 'erase_user_data'],
        ];

        return $erasers;
    }

    /**
     * Exports a user's community data for WordPress privacy requests.
     *
     * @param string $email_address Email address of the user to export.
     * @param int    $page          Pagination page number.
     * @return array<string, mixed>
     */
    public static function export_user_data(string $email_address, int $page = 1): array
    {
        global $wpdb;

        $user = get_user_by('email', $email_address);
        $data = [];
        $done = true;

        if (! $user) {
            return [
                'data' => $data,
                'done' => $done,
            ];
        }

        $user_id = (int) $user->ID;

        $memberships = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT members.community_id, members.role, members.created_at, communities.name AS community_name FROM ' . Database::community_members_table() . ' members LEFT JOIN ' . Database::communities_table() . ' communities ON communities.id = members.community_id WHERE members.user_id = %d ORDER BY members.created_at DESC',
                $user_id
            )
        );

        foreach ($memberships as $membership) {
            $data[] = [
                'group_id'    => 'common-goals-memberships',
                'group_label' => __('Common Goals Memberships', 'common-goals'),
                'item_id'     => 'cg-membership-' . $membership->community_id,
                'data'        => [
                    ['name' => __('Community', 'common-goals'), 'value' => $membership->community_name ?: '#' . $membership->community_id],
                    ['name' => __('Role', 'common-goals'), 'value' => $membership->role],
                    ['name' => __('Date', 'common-goals'), 'value' => $membership->created_at],
                ],
            ];
        }

        $contributions = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, goal_id, type, status, topic, title, body, created_at FROM ' . Database::contributions_table() . ' WHERE user_id = %d ORDER BY created_at DESC',
                $user_id
            )
        );

        foreach ($contributions as $contribution) {
            $data[] = [
                'group_id'    => 'common-goals-contributions',
                'group_label' => __('Common Goals Contributions', 'common-goals'),
                'item_id'     => 'cg-contribution-' . $contribution->id,
                'data'        => [
                    ['name' => __('Title', 'common-goals'), 'value' => $contribution->title],
                    ['name' => __('Type', 'common-goals'), 'value' => $contribution->type],
                    ['name' => __('Status', 'common-goals'), 'value' => $contribution->status],
                    ['name' => __('Topic', 'common-goals'), 'value' => $contribution->topic],
                    ['name' => __('Body', 'common-goals'), 'value' => $contribution->body],
                    ['name' => __('Date', 'common-goals'), 'value' => $contribution->created_at],
                ],
            ];
        }

        $responses = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, contribution_id, body, created_at FROM ' . Database::responses_table() . ' WHERE user_id = %d ORDER BY created_at DESC',
                $user_id
            )
        );

        foreach ($responses as $response) {
            $data[] = [
                'group_id'    => 'common-goals-responses',
                'group_label' => __('Common Goals Responses', 'common-goals'),
                'item_id'     => 'cg-response-' . $response->id,
                'data'        => [
                    ['name' => __('Contribution', 'common-goals'), 'value' => '#' . $response->contribution_id],
                    ['name' => __('Body', 'common-goals'), 'value' => $response->body],
                    ['name' => __('Date', 'common-goals'), 'value' => $response->created_at],
                ],
            ];
        }

        return [
            'data' => $data,
            'done' => $done,
        ];
    }

    /**
     * Anonymizes a user's community data for WordPress erasure requests.
     *
     * @param string $email_address Email address of the user to erase.
     * @param int    $page          Pagination page number.
     * @return array<string, mixed>
     */
    public static function erase_user_data(string $email_address, int $page = 1): array
    {
        global $wpdb;

        $user         = get_user_by('email', $email_address);
        $items_removed = 0;
        $done          = true;

        if (! $user) {
            return [
                'items_removed'  => $items_removed,
                'items_retained' => false,
                'messages'       => [],
                'done'           => $done,
            ];
        }

        $user_id = (int) $user->ID;

        $contributions_table = Database::contributions_table();
        $responses_table     = Database::responses_table();
        $events_table        = Database::events_table();
        $members_table       = Database::community_members_table();

        $wpdb->delete($members_table, ['user_id' => $user_id], ['%d']);
        $items_removed += (int) $wpdb->rows_affected;

        $wpdb->update($contributions_table, ['user_id' => 0], ['user_id' => $user_id], ['%d'], ['%d']);
        $items_removed += (int) $wpdb->rows_affected;

        $wpdb->update($responses_table, ['user_id' => 0], ['user_id' => $user_id], ['%d'], ['%d']);
        $items_removed += (int) $wpdb->rows_affected;

        $wpdb->update($events_table, ['created_by' => 0], ['created_by' => $user_id], ['%d'], ['%d']);
        $items_removed += (int) $wpdb->rows_affected;

        return [
            'items_removed'  => $items_removed,
            'items_retained' => false,
            'messages'       => [__('Common Goals community data has been anonymized.', 'common-goals')],
            'done'           => $done,
        ];
    }

    /**
     * Anonymizes community content when a user is deleted from WordPress.
     *
     * @param int      $user_id  ID of the user being deleted.
     * @param int|null $reassign ID of the user to reassign posts to, or null.
     */
    public static function anonymize_user_data(int $user_id, ?int $reassign = null): void
    {
        global $wpdb;

        $user_id = (int) $user_id;

        $wpdb->update(Database::contributions_table(), ['user_id' => 0], ['user_id' => $user_id], ['%d'], ['%d']);
        $wpdb->update(Database::responses_table(), ['user_id' => 0], ['user_id' => $user_id], ['%d'], ['%d']);
        $wpdb->update(Database::events_table(), ['created_by' => 0], ['created_by' => $user_id], ['%d'], ['%d']);
        $wpdb->delete(Database::community_members_table(), ['user_id' => $user_id], ['%d']);
    }

    /**
     * Adds the suggested privacy policy text to the WordPress policy page.
     */
    public static function add_privacy_policy_content(): void
    {
        if (! function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = '<p>' . __('Common Goals stores community memberships, contributions and responses linked to user accounts. When you submit content, your user ID is recorded alongside the submission. Guest submissions are attributed to user ID 0 (anonymous).', 'common-goals') . '</p>';
        $content .= '<p>' . __('If you request data export or erasure, your community contributions and responses will be included. Upon erasure, your content is anonymized (user ID set to 0) but the content itself is retained to preserve community continuity.', 'common-goals') . '</p>';
        $content .= '<p>' . __('The plugin does not collect IP addresses, cookies, or browser fingerprints.', 'common-goals') . '</p>';

        wp_add_privacy_policy_content('Common Goals', wp_kses_post($content));
    }
}
