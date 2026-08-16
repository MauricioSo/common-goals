<?php
/**
 * Admin template for the Common Goals settings page.
 *
 * @package CommonGoals
 */

if (! defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php echo esc_html__('Common Goals Settings', 'common-goals'); ?></h1>

    <form method="post" action="options.php">
        <?php
        settings_fields(SettingsPage::OPTION_GROUP);
        do_settings_sections(SettingsPage::PAGE_SLUG);
        submit_button();
        ?>
    </form>
</div>
