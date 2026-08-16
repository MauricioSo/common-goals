<?php
/**
 * Plugin Name: Common Goals
 * Description: Create goal-oriented community boards with contributions, responses, and living guides.
 * Version: 2.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Mauricio Soto
 * Author URI: https://heymauricio.com
 * Plugin URI: https://github.com/MauricioSo/common-goals
 * Text Domain: common-goals
 * Domain Path: /languages
 * License: GPLv2 or later
 *
 * @package CommonGoals
 */

if (! defined('ABSPATH')) {
    exit;
}

/* Dependencies. */
require_once __DIR__ . '/includes/Domain.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Markdown.php';
require_once __DIR__ . '/includes/Migrator.php';
require_once __DIR__ . '/includes/Capabilities.php';
require_once __DIR__ . '/includes/Activator.php';
require_once __DIR__ . '/includes/EventLogger.php';
require_once __DIR__ . '/includes/Privacy.php';
require_once __DIR__ . '/includes/Exporter.php';
require_once __DIR__ . '/includes/SiteHealth.php';
require_once __DIR__ . '/includes/TemplateLoader.php';
require_once __DIR__ . '/includes/GuideSitemap.php';
require_once __DIR__ . '/includes/GuideSitemapProvider.php';
require_once __DIR__ . '/includes/Notifications.php';
require_once __DIR__ . '/includes/InAppNotifications.php';
require_once __DIR__ . '/includes/RestApi.php';
require_once __DIR__ . '/includes/TaskRunner.php';
require_once __DIR__ . '/includes/Blocks.php';
require_once __DIR__ . '/includes/Blocks/BoardBlock.php';
require_once __DIR__ . '/includes/Blocks/GuidesBlock.php';
require_once __DIR__ . '/includes/Admin/ContributionsAdminPage.php';
require_once __DIR__ . '/includes/Admin/EventsAdminPage.php';
require_once __DIR__ . '/includes/Admin/GuidesAdminPage.php';
require_once __DIR__ . '/includes/Admin/GoalsAdminPage.php';
require_once __DIR__ . '/includes/Admin/SettingsPage.php';
require_once __DIR__ . '/includes/Admin/CommunitiesAdminPage.php';
require_once __DIR__ . '/includes/Admin/ReportsAdminPage.php';
require_once __DIR__ . '/includes/Frontend/BoardShortcode.php';
require_once __DIR__ . '/includes/Frontend/GuidesShortcode.php';
require_once __DIR__ . '/includes/Frontend/GuideRouter.php';
require_once __DIR__ . '/includes/AI/CompletionResult.php';
require_once __DIR__ . '/includes/AI/CompletionClient.php';
require_once __DIR__ . '/includes/AI/Settings.php';
require_once __DIR__ . '/includes/AI/Client.php';
require_once __DIR__ . '/includes/AI/OutputValidator.php';
require_once __DIR__ . '/includes/AI/ContextBuilder.php';
require_once __DIR__ . '/includes/AI/Prompts.php';
require_once __DIR__ . '/includes/AI/BudgetGuard.php';
require_once __DIR__ . '/includes/AI/Flow/AbstractFlow.php';
require_once __DIR__ . '/includes/AI/Flow/DiscoverFlow.php';
require_once __DIR__ . '/includes/AI/Flow/ComposeFlow.php';
require_once __DIR__ . '/includes/AI/Flow/AnswerFlow.php';
require_once __DIR__ . '/includes/AI/Flow/SummarizeFlow.php';
require_once __DIR__ . '/includes/AI/Flow/OrganizeFlow.php';
require_once __DIR__ . '/includes/AI/Flow/ModerateFlow.php';
require_once __DIR__ . '/includes/AI/Flow/GuideFlow.php';
require_once __DIR__ . '/includes/AI/AiRouter.php';
require_once __DIR__ . '/includes/Admin/AiSettingsPage.php';
require_once __DIR__ . '/includes/Plugin.php';

/* Variables. */
define('COMMON_GOALS_VERSION', '2.0.0');
define('COMMON_GOALS_PLUGIN_FILE', __FILE__);
define('COMMON_GOALS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('COMMON_GOALS_PLUGIN_URL', plugin_dir_url(__FILE__));

/* Functions. */
/**
 * Starts the Common Goals plugin after WordPress has loaded plugins.
 */
function common_goals_start_plugin(): void
{
    load_plugin_textdomain('common-goals', false, dirname(plugin_basename(__FILE__)) . '/languages');

    CommonGoals\Migrator::run();

    $plugin = new CommonGoals\Plugin();
    $plugin->register_hooks();
}

/* Processing. */
register_activation_hook(__FILE__, [CommonGoals\Activator::class, 'activate']);
add_action('plugins_loaded', 'common_goals_start_plugin');
