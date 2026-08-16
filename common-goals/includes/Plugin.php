<?php
/**
 * Main plugin coordinator.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

use CommonGoals\Admin\ContributionsAdminPage;
use CommonGoals\Admin\EventsAdminPage;
use CommonGoals\Admin\GuidesAdminPage;
use CommonGoals\Admin\GoalsAdminPage;
use CommonGoals\Admin\SettingsPage;
use CommonGoals\Admin\CommunitiesAdminPage;
use CommonGoals\Admin\ReportsAdminPage;
use CommonGoals\Admin\AiSettingsPage;
use CommonGoals\AI\AiRouter;
use CommonGoals\Frontend\BoardShortcode;
use CommonGoals\Frontend\GuideRouter;
use CommonGoals\Frontend\GuidesShortcode;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers the plugin modules with WordPress.
 */
final class Plugin
{
    /**
     * Registers WordPress hooks for all plugin modules.
     */
    public function register_hooks(): void
    {
        $goals_admin_page         = new GoalsAdminPage();
        $contributions_admin_page = new ContributionsAdminPage();
        $guides_admin_page        = new GuidesAdminPage();
        $events_admin_page        = new EventsAdminPage();
        $settings_page            = new SettingsPage();
        $communities_admin_page   = new CommunitiesAdminPage();
        $reports_admin_page       = new ReportsAdminPage();
        $ai_settings_page         = new AiSettingsPage();
        $board_shortcode          = new BoardShortcode();
        $guides_shortcode         = new GuidesShortcode();
        $guide_router             = new GuideRouter();

        $goals_admin_page->register_hooks();
        $contributions_admin_page->register_hooks();
        $guides_admin_page->register_hooks();
        $events_admin_page->register_hooks();
        $settings_page->register_hooks();
        $communities_admin_page->register_hooks();
        $reports_admin_page->register_hooks();
        $ai_settings_page->register_hooks();
        $board_shortcode->register_hooks();
        $guides_shortcode->register_hooks();
        $guide_router->register_hooks();

        Privacy::register_hooks();
        SiteHealth::register_hooks();
        GuideSitemap::register_hooks();
        Notifications::register_hooks();
        InAppNotifications::register_hooks();
        RestApi::register_hooks();
        AiRouter::register_hooks();
        Blocks::register_hooks();

        add_action('admin_post_common_goals_export', [Exporter::class, 'download']);
    }
}
