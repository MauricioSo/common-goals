<?php
/**
 * Admin template for managing communities.
 *
 * @var array $communities Community rows with goal_count and members.
 *
 * @package CommonGoals
 */

if (! defined('ABSPATH')) {
    exit;
}

$role_labels = [
    'admin'     => __('Admin', 'common-goals'),
    'moderator' => __('Moderator', 'common-goals'),
    'member'    => __('Member', 'common-goals'),
];
?>

<div class="wrap">
    <h1><?php echo esc_html__('Common Goals Communities', 'common-goals'); ?></h1>

    <?php
    $notice_code = isset($_GET['common_goals_notice']) ? sanitize_key(wp_unslash($_GET['common_goals_notice'])) : '';
    $error_codes = ['invalid_community', 'db_error', 'invalid_member'];
    if ($notice_code !== '') :
        $is_error = in_array($notice_code, $error_codes, true);
    ?>
        <div class="notice <?php echo $is_error ? 'notice-error' : 'notice-success'; ?> is-dismissible">
            <p><?php echo esc_html($is_error ? __('An error occurred.', 'common-goals') : __('Action completed.', 'common-goals')); ?></p>
        </div>
    <?php endif; ?>

    <?php if (! empty($can_manage_all)) : ?>
        <h2><?php echo esc_html__('Create Community', 'common-goals'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="common_goals_create_community" />
            <?php wp_nonce_field('common_goals_create_community'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="community_name"><?php echo esc_html__('Name', 'common-goals'); ?></label></th>
                    <td><input id="community_name" class="regular-text" type="text" name="community_name" required /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="community_description"><?php echo esc_html__('Description', 'common-goals'); ?></label></th>
                    <td><textarea id="community_description" class="large-text" name="community_description" rows="3"></textarea></td>
                </tr>
            </table>
            <?php submit_button(__('Create community', 'common-goals')); ?>
        </form>

        <hr />
    <?php endif; ?>

    <h2><?php echo esc_html__('Existing Communities', 'common-goals'); ?></h2>

    <?php if (! empty($communities)) : ?>
        <?php foreach ($communities as $community) : ?>
            <div class="card" style="max-width: 900px; margin-top: 18px; padding: 18px;">
                <h3><?php echo esc_html($community->name); ?>
                    <code style="font-size:13px; color:#666;">(<?php echo esc_html($community->slug); ?>)</code>
                </h3>
                <p>
                    <strong><?php echo esc_html__('Status:', 'common-goals'); ?></strong> <?php echo esc_html($community->status); ?>
                    <strong><?php echo esc_html__('Goals:', 'common-goals'); ?></strong> <?php echo esc_html((string) $community->goal_count); ?>
                </p>

                <details>
                    <summary><?php echo esc_html__('Edit community', 'common-goals'); ?></summary>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 12px;">
                        <input type="hidden" name="action" value="common_goals_update_community" />
                        <input type="hidden" name="community_id" value="<?php echo esc_attr((string) $community->id); ?>" />
                        <?php wp_nonce_field('common_goals_update_community'); ?>
                        <p>
                            <input class="large-text" type="text" name="community_name" value="<?php echo esc_attr($community->name); ?>" required />
                        </p>
                        <p>
                            <textarea class="large-text" name="community_description" rows="2"><?php echo esc_textarea($community->description); ?></textarea>
                        </p>
                        <p>
                            <select name="community_status">
                                <option value="active" <?php selected($community->status, 'active'); ?>><?php echo esc_html__('Active', 'common-goals'); ?></option>
                                <option value="inactive" <?php selected($community->status, 'inactive'); ?>><?php echo esc_html__('Inactive', 'common-goals'); ?></option>
                            </select>
                            <?php submit_button(__('Save', 'common-goals'), 'secondary', 'submit', false); ?>
                        </p>
                    </form>
                </details>

                <details style="margin-top: 14px;">
                    <summary><?php echo esc_html__('Members', 'common-goals'); ?> (<?php echo esc_html((string) count($community->members ?? [])); ?>)</summary>

                    <table class="widefat striped" style="margin-top: 12px; max-width: 700px;">
                        <thead><tr>
                            <th><?php echo esc_html__('User', 'common-goals'); ?></th>
                            <th><?php echo esc_html__('Role', 'common-goals'); ?></th>
                            <th><?php echo esc_html__('Remove', 'common-goals'); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach (($community->members ?? []) as $member) : ?>
                                <?php $user = get_userdata((int) $member->user_id); ?>
                                <tr>
                                    <td><?php echo esc_html($user ? $user->display_name : '#' . $member->user_id); ?></td>
                                    <td><?php echo esc_html($role_labels[$member->role] ?? $member->role); ?></td>
                                    <td>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                            <input type="hidden" name="action" value="common_goals_remove_member" />
                                            <input type="hidden" name="community_id" value="<?php echo esc_attr((string) $community->id); ?>" />
                                            <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $member->user_id); ?>" />
                                            <?php wp_nonce_field('common_goals_remove_member'); ?>
                                            <button type="submit" class="button button-small"><?php echo esc_html__('Remove', 'common-goals'); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 12px; display: flex; gap: 8px; align-items: end; flex-wrap: wrap;">
                        <input type="hidden" name="action" value="common_goals_add_member" />
                        <input type="hidden" name="community_id" value="<?php echo esc_attr((string) $community->id); ?>" />
                        <?php wp_nonce_field('common_goals_add_member'); ?>
                        <label><?php echo esc_html__('User ID', 'common-goals'); ?><br />
                            <input type="number" name="user_id" min="1" style="width: 100px;" required />
                        </label>
                        <label><?php echo esc_html__('Role', 'common-goals'); ?><br />
                            <select name="member_role">
                                <option value="member"><?php echo esc_html__('Member', 'common-goals'); ?></option>
                                <option value="moderator"><?php echo esc_html__('Moderator', 'common-goals'); ?></option>
                                <option value="admin"><?php echo esc_html__('Admin', 'common-goals'); ?></option>
                            </select>
                        </label>
                        <?php submit_button(__('Add member', 'common-goals'), 'secondary', 'submit', false); ?>
                    </form>
                </details>
            </div>
        <?php endforeach; ?>
    <?php else : ?>
        <p><?php echo esc_html__('No communities exist yet. The migration should have created a default one — try reactivating the plugin.', 'common-goals'); ?></p>
    <?php endif; ?>
</div>
