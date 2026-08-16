<?php
/**
 * Custom capabilities and role management.
 *
 * @package CommonGoals
 */

namespace CommonGoals;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers granular capabilities so community moderation does not
 * require full administrator privileges.
 */
final class Capabilities
{
    public const MANAGE          = 'manage_common_goals';
    public const MODERATE        = 'moderate_common_goals';
    public const PUBLISH_GUIDES  = 'publish_common_goals_guides';
    public const VIEW_EVENTS     = 'view_common_goals_events';

    public const MODERATOR_ROLE  = 'common_goals_moderator';

    /**
     * Adds capabilities to the administrator role and creates a dedicated
     * moderator role. Safe to call multiple times.
     */
    public static function register(): void
    {
        $admin = get_role('administrator');

        if ($admin) {
            $admin->add_cap(self::MANAGE);
            $admin->add_cap(self::MODERATE);
            $admin->add_cap(self::PUBLISH_GUIDES);
            $admin->add_cap(self::VIEW_EVENTS);
        }

        if (! get_role(self::MODERATOR_ROLE)) {
            add_role(self::MODERATOR_ROLE, __('Common Goals Moderator', 'common-goals'), [
                'read'             => true,
                self::MODERATE     => true,
                self::VIEW_EVENTS  => true,
            ]);
        }

        $editor = get_role('editor');

        if ($editor) {
            $editor->add_cap(self::MODERATE);
            $editor->add_cap(self::VIEW_EVENTS);
        }
    }

    /**
     * Removes capabilities and the moderator role during uninstall.
     */
    public static function unregister(): void
    {
        foreach (['administrator', 'editor'] as $role_name) {
            $role = get_role($role_name);

            if (! $role) {
                continue;
            }

            $role->remove_cap(self::MANAGE);
            $role->remove_cap(self::MODERATE);
            $role->remove_cap(self::PUBLISH_GUIDES);
            $role->remove_cap(self::VIEW_EVENTS);
        }

        remove_role(self::MODERATOR_ROLE);
    }

    /**
     * Returns the capability required to manage a given entity type.
     */
    public static function for_entity(string $entity): string
    {
        return match ($entity) {
            'goal'         => self::MANAGE,
            'contribution' => self::MODERATE,
            'guide'        => self::PUBLISH_GUIDES,
            'event'        => self::VIEW_EVENTS,
            default        => self::MANAGE,
        };
    }
}
