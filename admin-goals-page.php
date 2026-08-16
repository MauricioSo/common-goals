<?php
/**
 * Admin template for the Common Goals goals page.
 *
 * @package CommonGoals
 */

if (! defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php echo esc_html__('Common Goals', 'common-goals'); ?></h1>

    <?php
    $notice_code = isset($_GET['common_goals_notice']) ? sanitize_key(wp_unslash($_GET['common_goals_notice'])) : '';
    $error_codes = ['missing_required_fields', 'invalid_goal', 'invalid_community', 'db_error'];
    if ($notice_code !== '') :
        $is_error = in_array($notice_code, $error_codes, true);
    ?>
        <div class="notice <?php echo $is_error ? 'notice-error' : 'notice-success'; ?> is-dismissible">
            <p><?php echo esc_html($is_error ? __('An error occurred. Please try again.', 'common-goals') : __('Action completed successfully.', 'common-goals')); ?></p>
        </div>
    <?php endif; ?>

    <h2><?php echo esc_html__('Create Community Goal', 'common-goals'); ?></h2>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="common_goals_create_goal" />
        <?php wp_nonce_field('common_goals_create_goal'); ?>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="community_id"><?php echo esc_html__('Community', 'common-goals'); ?></label>
                    </th>
                    <td>
                        <select id="community_id" name="community_id" required>
                            <?php foreach (($communities ?? []) as $community) : ?>
                                <option value="<?php echo esc_attr((string) $community->id); ?>"><?php echo esc_html($community->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="goal_title"><?php echo esc_html__('Goal title', 'common-goals'); ?></label>
                    </th>
                    <td>
                        <input id="goal_title" class="regular-text" type="text" name="goal_title" required />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="goal_description"><?php echo esc_html__('Description', 'common-goals'); ?></label>
                    </th>
                    <td>
                        <textarea id="goal_description" class="large-text" name="goal_description" rows="5" required></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="goal_beneficiary"><?php echo esc_html__('Who benefits?', 'common-goals'); ?></label>
                    </th>
                    <td>
                        <input id="goal_beneficiary" class="regular-text" type="text" name="goal_beneficiary" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Allowed contribution types', 'common-goals'); ?></th>
                    <td>
                        <?php
                        $all_types = [
                            'question'   => __('Question', 'common-goals'),
                            'problem'    => __('Problem', 'common-goals'),
                            'experience' => __('Experience', 'common-goals'),
                            'resource'   => __('Resource', 'common-goals'),
                        ];
                        foreach ($all_types as $type_value => $type_label) :
                            $id = 'goal_type_' . $type_value;
                        ?>
                            <label for="<?php echo esc_attr($id); ?>" style="display: inline-block; margin-right: 16px;">
                                <input id="<?php echo esc_attr($id); ?>" type="checkbox" name="goal_types[]" value="<?php echo esc_attr($type_value); ?>" checked />
                                <?php echo esc_html($type_label); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description"><?php echo esc_html__('Select which contribution types members can submit to this goal.', 'common-goals'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="goal_alignment_rules"><?php echo esc_html__('Alignment rules', 'common-goals'); ?></label>
                    </th>
                    <td>
                        <textarea id="goal_alignment_rules" class="large-text" name="goal_alignment_rules" rows="4"></textarea>
                        <p class="description"><?php echo esc_html__('Explain what belongs in this community and what does not.', 'common-goals'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php submit_button(__('Create goal', 'common-goals')); ?>
    </form>

    <hr />

    <h2><?php echo esc_html__('Existing Goals', 'common-goals'); ?></h2>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php echo esc_html__('ID', 'common-goals'); ?></th>
                <th><?php echo esc_html__('Title', 'common-goals'); ?></th>
                <th><?php echo esc_html__('Community', 'common-goals'); ?></th>
                <th><?php echo esc_html__('Status', 'common-goals'); ?></th>
                <th><?php echo esc_html__('Shortcode', 'common-goals'); ?></th>
                <th><?php echo esc_html__('Edit', 'common-goals'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (! empty($goals)) : ?>
                <?php foreach ($goals as $goal) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $goal->id); ?></td>
                        <td><?php echo esc_html($goal->title); ?></td>
                        <td>
                            <?php
                            $community_name = '';
                            foreach (($communities ?? []) as $community) {
                                if ((int) $community->id === (int) $goal->community_id) {
                                    $community_name = $community->name;
                                    break;
                                }
                            }
                            echo esc_html($community_name !== '' ? $community_name : __('Inactive or missing', 'common-goals'));
                            ?>
                        </td>
                        <td><?php echo esc_html($goal->status); ?></td>
                        <td><code>[common_goals_board goal_id="<?php echo esc_attr((string) $goal->id); ?>" community_id="<?php echo esc_attr((string) $goal->community_id); ?>"]</code></td>
                        <td>
                            <details>
                                <summary><?php echo esc_html__('Edit goal', 'common-goals'); ?></summary>

                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 12px; min-width: 520px;">
                                    <input type="hidden" name="action" value="common_goals_update_goal" />
                                    <input type="hidden" name="goal_id" value="<?php echo esc_attr((string) $goal->id); ?>" />
                                    <?php wp_nonce_field('common_goals_update_goal'); ?>

                                    <p>
                                        <label>
                                            <strong><?php echo esc_html__('Community', 'common-goals'); ?></strong><br />
                                            <select name="community_id" required>
                                                <?php foreach (($communities ?? []) as $community) : ?>
                                                    <option value="<?php echo esc_attr((string) $community->id); ?>" <?php selected((int) $goal->community_id, (int) $community->id); ?>><?php echo esc_html($community->name); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                    </p>

                                    <p>
                                        <label>
                                            <strong><?php echo esc_html__('Title', 'common-goals'); ?></strong><br />
                                            <input class="large-text" type="text" name="goal_title" value="<?php echo esc_attr($goal->title); ?>" required />
                                        </label>
                                    </p>

                                    <p>
                                        <label>
                                            <strong><?php echo esc_html__('Description', 'common-goals'); ?></strong><br />
                                            <textarea class="large-text" name="goal_description" rows="4" required><?php echo esc_textarea($goal->description); ?></textarea>
                                        </label>
                                    </p>

                                    <p>
                                        <label>
                                            <strong><?php echo esc_html__('Who benefits?', 'common-goals'); ?></strong><br />
                                            <input class="large-text" type="text" name="goal_beneficiary" value="<?php echo esc_attr($goal->beneficiary); ?>" />
                                        </label>
                                    </p>

                                    <p>
                                        <strong><?php echo esc_html__('Allowed contribution types', 'common-goals'); ?></strong><br />
                                        <?php
                                        $goal_types = json_decode($goal->allowed_contribution_types, true);
                                        if (! is_array($goal_types)) {
                                            $goal_types = ['question', 'problem', 'experience', 'resource'];
                                        }
                                        $all_types = [
                                            'question'   => __('Question', 'common-goals'),
                                            'problem'    => __('Problem', 'common-goals'),
                                            'experience' => __('Experience', 'common-goals'),
                                            'resource'   => __('Resource', 'common-goals'),
                                        ];
                                        foreach ($all_types as $type_value => $type_label) :
                                        ?>
                                            <label style="display: inline-block; margin-right: 16px;">
                                                <input type="checkbox" name="goal_types[]" value="<?php echo esc_attr($type_value); ?>" <?php checked(in_array($type_value, $goal_types, true)); ?> />
                                                <?php echo esc_html($type_label); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </p>

                                    <p>
                                        <label>
                                            <strong><?php echo esc_html__('Alignment rules', 'common-goals'); ?></strong><br />
                                            <textarea class="large-text" name="goal_alignment_rules" rows="4"><?php echo esc_textarea($goal->alignment_rules); ?></textarea>
                                        </label>
                                    </p>

                                    <p>
                                        <label>
                                            <strong><?php echo esc_html__('Status', 'common-goals'); ?></strong><br />
                                            <select name="goal_status">
                                                <option value="active" <?php selected($goal->status, 'active'); ?>><?php echo esc_html__('Active', 'common-goals'); ?></option>
                                                <option value="inactive" <?php selected($goal->status, 'inactive'); ?>><?php echo esc_html__('Inactive', 'common-goals'); ?></option>
                                            </select>
                                        </label>
                                    </p>

                                    <?php submit_button(__('Save goal', 'common-goals'), 'secondary', 'submit', false); ?>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr>
                    <td colspan="6"><?php echo esc_html__('No goals created yet.', 'common-goals'); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
