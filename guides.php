<?php
/**
 * Public template for Common Goals living guides.
 *
 * Expected variables:
 *
 * @var array $guides Published guides.
 *
 * @package CommonGoals
 */

if (! defined('ABSPATH')) {
    exit;
}

use CommonGoals\Frontend\GuideRouter;
?>

<section class="common-goals-guides">
    <header class="common-goals-guides__header">
        <p class="common-goals-board__eyebrow"><?php echo esc_html__('Living Guides', 'common-goals'); ?></p>
        <h2><?php echo esc_html__('Community Knowledge', 'common-goals'); ?></h2>
    </header>

    <?php if (! empty($guides)) : ?>
        <div class="common-goals-guides__list">
            <?php foreach ($guides as $guide) : ?>
                <article id="common-goals-guide-<?php echo esc_attr($guide->slug); ?>" class="common-goals-guide">
                    <h3>
                        <a href="<?php echo esc_url(GuideRouter::guide_url($guide->slug)); ?>">
                            <?php echo esc_html($guide->title); ?>
                        </a>
                    </h3>
                    <div><?php echo wp_kses_post(wpautop(wp_trim_words($guide->content, 40, '...'))); ?></div>
                    <p class="common-goals-muted">
                        <?php echo esc_html__('Updated:', 'common-goals'); ?>
                        <?php echo esc_html(mysql2date(get_option('date_format'), $guide->updated_at)); ?>
                    </p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else : ?>
        <p><?php echo esc_html__('No living guides have been published yet.', 'common-goals'); ?></p>
    <?php endif; ?>
</section>
