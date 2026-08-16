<?php
/**
 * Email notifications for community events.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Sends configurable email notifications to moderators and authors when
 * community events happen. All recipients, subjects and bodies are
 * filterable for custom integrations.
 */
final class Notifications
{
    /**
     * Registers WordPress hooks.
     */
    public static function register_hooks(): void
    {
        add_action('common_goals_contribution_created', [self::class, 'notify_moderators_pending'], 10, 2);
        add_action('common_goals_response_created', [self::class, 'notify_contribution_author'], 10, 2);
        add_action('common_goals_contribution_status_changed', [self::class, 'notify_author_approved'], 10, 3);
    }

    /**
     * Notifies all moderators when a pending contribution is submitted.
     *
     * @param int                  $contribution_id Contribution ID.
     * @param array<string, mixed> $data            Contribution metadata.
     */
    public static function notify_moderators_pending(int $contribution_id, array $data): void
    {
        if (($data['status'] ?? '') !== 'pending') {
            return;
        }

        global $wpdb;

        $table     = Database::contributions_table();
        $contribution = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $contribution_id));

        if (! $contribution) {
            return;
        }

        $recipients = self::get_moderator_emails();

        if (empty($recipients)) {
            return;
        }

        $recipients = apply_filters('common_goals_notification_recipients', $recipients, 'moderator_pending', $contribution_id);

        $subject = sprintf(
            /* translators: 1: site name, 2: contribution title. */
            __('[%1$s] New contribution awaiting moderation: %2$s', 'common-goals'),
            get_bloginfo('name'),
            $contribution->title
        );

        $message  = __("A new contribution is awaiting your review.\n\n", 'common-goals');
        /* translators: %s: contribution title. */
        $message .= sprintf(__("Title: %s\n", 'common-goals'), $contribution->title) . "\n";
        /* translators: %s: contribution type. */
        $message .= sprintf(__("Type: %s\n", 'common-goals'), $contribution->type) . "\n";
        /* translators: %s: contribution topic. */
        $message .= sprintf(__("Topic: %s\n", 'common-goals'), $contribution->topic) . "\n";
        $message .= admin_url('admin.php?page=common-goals-contributions') . "\n";

        $subject = apply_filters('common_goals_notification_subject', $subject, 'moderator_pending', $contribution_id);
        $message = apply_filters('common_goals_notification_body', $message, 'moderator_pending', $contribution_id);

        foreach ($recipients as $email) {
            wp_mail($email, $subject, $message);
        }
    }

    /**
     * Notifies the contribution author when a response is published.
     *
     * @param int                  $response_id Response ID.
     * @param array<string, mixed> $data        Response metadata.
     */
    public static function notify_contribution_author(int $response_id, array $data): void
    {
        if (($data['status'] ?? '') !== 'published') {
            return;
        }

        global $wpdb;

        $responses_table     = Database::responses_table();
        $contributions_table = Database::contributions_table();

        $response = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$responses_table} WHERE id = %d", $response_id));

        if (! $response) {
            return;
        }

        $contribution = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$contributions_table} WHERE id = %d", (int) $response->contribution_id)
        );

        if (! $contribution || $contribution->user_id <= 0) {
            return;
        }

        if ((int) $contribution->user_id === (int) $response->user_id) {
            return;
        }

        $author = get_userdata((int) $contribution->user_id);

        if (! $author) {
            return;
        }

        $subject = sprintf(
            /* translators: 1: site name, 2: contribution title. */
            __('[%1$s] New response to your contribution: %2$s', 'common-goals'),
            get_bloginfo('name'),
            $contribution->title
        );

        $message  = __("Someone responded to your contribution.\n\n", 'common-goals');
        /* translators: %s: contribution title. */
        $message .= sprintf(__("Contribution: %s\n", 'common-goals'), $contribution->title) . "\n";
        $message .= wp_strip_all_tags(wp_trim_words($response->body, 30)) . "\n";

        $subject = apply_filters('common_goals_notification_subject', $subject, 'author_response', $response_id);
        $message = apply_filters('common_goals_notification_body', $message, 'author_response', $response_id);

        wp_mail($author->user_email, $subject, $message);
    }

    /**
     * Notifies the author when their contribution is approved.
     *
     * @param int    $contribution_id Contribution ID.
     * @param string $old_status      Previous status.
     * @param string $new_status      New status.
     */
    public static function notify_author_approved(int $contribution_id, string $old_status, string $new_status): void
    {
        if ($old_status !== 'pending' || ! in_array($new_status, ['open', 'in_progress', 'resolved'], true)) {
            return;
        }

        global $wpdb;

        $table        = Database::contributions_table();
        $contribution = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $contribution_id));

        if (! $contribution || $contribution->user_id <= 0) {
            return;
        }

        $author = get_userdata((int) $contribution->user_id);

        if (! $author) {
            return;
        }

        $subject = sprintf(
            /* translators: 1: site name, 2: contribution title. */
            __('[%1$s] Your contribution has been approved: %2$s', 'common-goals'),
            get_bloginfo('name'),
            $contribution->title
        );

        $message  = __("Your contribution has been approved and is now visible.\n\n", 'common-goals');
        /* translators: %s: contribution title. */
        $message .= sprintf(__("Title: %s\n", 'common-goals'), $contribution->title) . "\n";
        /* translators: %s: contribution status. */
        $message .= sprintf(__("Status: %s\n", 'common-goals'), $new_status) . "\n";

        $subject = apply_filters('common_goals_notification_subject', $subject, 'author_approved', $contribution_id);
        $message = apply_filters('common_goals_notification_body', $message, 'author_approved', $contribution_id);

        wp_mail($author->user_email, $subject, $message);
    }

    /**
     * Returns email addresses of all users who can moderate.
     *
     * @return string[]
     */
    private static function get_moderator_emails(): array
    {
        $users = get_users([
            'role__in' => ['administrator', 'editor', Capabilities::MODERATOR_ROLE],
            'fields'   => ['user_email'],
        ]);

        return array_filter(array_map(static fn ($u) => $u->user_email, $users));
    }
}
