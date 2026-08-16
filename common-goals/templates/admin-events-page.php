<?php
/**
 * Admin template for the Common Goals event log.
 *
 * Expected variables:
 *
 * @var array $events Recent recorded events.
 * @var array $communities Communities available for filtering.
 * @var int   $selected_community Selected community ID.
 *
 * @package CommonGoals
 */

if (! defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php echo esc_html__('Common Goals Events', 'common-goals'); ?></h1>

    <p><?php echo esc_html__('Recent community activity. This log feeds future analytics, exports, and Cloud synchronization.', 'common-goals'); ?></p>

    <form method="get" style="margin: 18px 0;">
        <input type="hidden" name="page" value="common-goals-events" />
        <label for="community_id">
            <strong><?php echo esc_html__('Community', 'common-goals'); ?></strong><br />
            <select id="community_id" name="community_id">
                <option value="0"><?php echo esc_html__('All communities', 'common-goals'); ?></option>
                <?php foreach (($communities ?? []) as $community) : ?>
                    <option value="<?php echo esc_attr((string) $community->id); ?>" <?php selected($selected_community ?? 0, (int) $community->id); ?>><?php echo esc_html($community->name); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php submit_button(__('Filter', 'common-goals'), 'secondary', 'submit', false); ?>
    </form>

    <div class="table-wrap" style="overflow-x: auto; max-width: 1180px;">
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php echo esc_html__('When', 'common-goals'); ?></th>
                    <th><?php echo esc_html__('Event', 'common-goals'); ?></th>
                    <th><?php echo esc_html__('Object', 'common-goals'); ?></th>
                    <th><?php echo esc_html__('By', 'common-goals'); ?></th>
                    <th><?php echo esc_html__('Context', 'common-goals'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (! empty($events)) : ?>
                    <?php foreach ($events as $event) : ?>
                        <tr>
                            <td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $event->created_at)); ?></td>
                            <td><code><?php echo esc_html($event->event_type); ?></code></td>
                            <td>
                                <?php echo esc_html($event->object_type); ?>
                                #<?php echo esc_html((string) $event->object_id); ?>
                            </td>
                            <td><?php echo esc_html((string) $event->created_by); ?></td>
                            <td>
                                <?php if (! empty($event->event_data)) : ?>
                                    <code style="white-space: pre-wrap; word-break: break-all;"><?php echo esc_html($event->event_data); ?></code>
                                <?php else : ?>
                                    <span aria-hidden="true">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="5"><?php echo esc_html__('No events recorded yet.', 'common-goals'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
