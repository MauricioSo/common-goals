<?php
/**
 * Template loader that allows themes to override plugin templates.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Resolves template paths by checking the active theme's
 * <code>common-goals/</code> directory before falling back to the
 * plugin's own <code>templates/</code> directory.
 *
 * To override a template, copy it to:
 *   <code>wp-content/themes/your-theme/common-goals/board.php</code>
 */
final class TemplateLoader
{
    public const THEME_DIR = 'common-goals';

    /**
     * Locates a template by file name.
     *
     * Checks the child theme, then the parent theme, then the plugin.
     *
     * @param string $template_name File name relative to the templates directory.
     * @return string Full path to the resolved template.
     */
    public static function locate(string $template_name): string
    {
        $located = '';

        $theme_candidates = [
            trailingslashit(get_stylesheet_directory()) . self::THEME_DIR . '/' . $template_name,
            trailingslashit(get_template_directory()) . self::THEME_DIR . '/' . $template_name,
        ];

        foreach ($theme_candidates as $candidate) {
            if (file_exists($candidate)) {
                $located = $candidate;
                break;
            }
        }

        if ($located === '') {
            $located = COMMON_GOALS_PLUGIN_DIR . 'templates/' . $template_name;
        }

        return $located;
    }

    /**
     * Loads a template, passing variables into scope.
     *
     * @param string                $template_name File name relative to templates directory.
     * @param array<string, mixed> $variables     Variables to extract into template scope.
     */
    public static function load(string $template_name, array $variables = []): void
    {
        $path = self::locate($template_name);

        if (! empty($variables)) {
            extract($variables, EXTR_SKIP);
        }

        include $path;
    }

    /**
     * Loads a template and returns its output as a string.
     *
     * @param string                $template_name File name relative to templates directory.
     * @param array<string, mixed> $variables     Variables to extract into template scope.
     */
    public static function capture(string $template_name, array $variables = []): string
    {
        ob_start();
        self::load($template_name, $variables);

        return (string) ob_get_clean();
    }
}
