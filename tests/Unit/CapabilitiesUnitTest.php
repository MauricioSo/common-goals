<?php
/**
 * Unit tests for Capabilities registration, removal and entity mapping.
 *
 * Covers spec cases UT-CAP-001 and UT-CAP-002.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use Brain\Monkey\Functions;
use CommonGoals\Capabilities;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

/**
 * Simple role spy that records cap changes.
 */
final class RoleSpy
{
    /** @var array<string, bool> */
    public array $caps = [];
    public bool $exists = true;

    public function add_cap(string $cap): void
    {
        $this->caps[$cap] = true;
    }

    public function remove_cap(string $cap): void
    {
        unset($this->caps[$cap]);
    }
}

final class CapabilitiesUnitTest extends UnitTestCase
{
    public function test_ut_cap_001_register_grants_four_caps_to_admin_and_creates_moderator(): void
    {
        $admin = new RoleSpy();
        $editor = new RoleSpy();
        $moderator = null;
        Functions\when('get_role')->alias(static function ($name) use ($admin, $editor) {
            return match ($name) {
                'administrator' => $admin,
                'editor' => $editor,
                default => null,
            };
        });
        Functions\when('add_role')->alias(static function ($name, $label, $caps) use (&$moderator) {
            $moderator = new RoleSpy();
            $moderator->caps = $caps;
            return $moderator;
        });

        Capabilities::register();

        $this->assertArrayHasKey(Capabilities::MANAGE, $admin->caps);
        $this->assertArrayHasKey(Capabilities::MODERATE, $admin->caps);
        $this->assertArrayHasKey(Capabilities::PUBLISH_GUIDES, $admin->caps);
        $this->assertArrayHasKey(Capabilities::VIEW_EVENTS, $admin->caps);

        $this->assertArrayHasKey(Capabilities::MODERATE, $editor->caps);
        $this->assertArrayHasKey(Capabilities::VIEW_EVENTS, $editor->caps);
        $this->assertArrayNotHasKey(Capabilities::MANAGE, $editor->caps);

        $this->assertNotNull($moderator);
        $this->assertTrue($moderator->caps['read'] ?? false);
        $this->assertArrayHasKey(Capabilities::MODERATE, $moderator->caps);
        $this->assertArrayHasKey(Capabilities::VIEW_EVENTS, $moderator->caps);
    }

    public function test_ut_cap_001_register_does_not_duplicate_moderator_role(): void
    {
        $existing = new RoleSpy();
        $created = false;
        Functions\when('get_role')->alias(static fn($name) => $name === Capabilities::MODERATOR_ROLE ? $existing : null);
        Functions\when('add_role')->alias(static function () use (&$created) {
            $created = true;
            return null;
        });

        Capabilities::register();

        $this->assertFalse($created, 'add_role must not run when the moderator role already exists');
    }

    public function test_ut_cap_001_register_skips_missing_admin_role_gracefully(): void
    {
        Functions\when('get_role')->justReturn(null);
        Functions\when('add_role')->justReturn(null);

        Capabilities::register();

        $this->addToAssertionCount(1);
    }

    public function test_ut_cap_001_unregister_removes_caps_and_role_without_error(): void
    {
        $admin = new RoleSpy();
        $editor = new RoleSpy();
        $role_removed = false;
        Functions\when('get_role')->alias(static fn($name) => match ($name) {
            'administrator' => $admin,
            'editor' => $editor,
            default => null,
        });
        Functions\when('remove_role')->alias(static function () use (&$role_removed) {
            $role_removed = true;
        });

        $admin->caps = [
            Capabilities::MANAGE => true,
            Capabilities::MODERATE => true,
            Capabilities::PUBLISH_GUIDES => true,
            Capabilities::VIEW_EVENTS => true,
        ];
        $editor->caps = [Capabilities::MODERATE => true];

        Capabilities::unregister();

        $this->assertArrayNotHasKey(Capabilities::MANAGE, $admin->caps);
        $this->assertArrayNotHasKey(Capabilities::MODERATE, $admin->caps);
        $this->assertArrayNotHasKey(Capabilities::MODERATE, $editor->caps);
        $this->assertTrue($role_removed);
    }

    public function test_ut_cap_001_unregister_skips_missing_roles_without_fatal(): void
    {
        Functions\when('get_role')->justReturn(null);
        Functions\when('remove_role')->justReturn(null);

        Capabilities::unregister();

        $this->addToAssertionCount(1);
    }

    public function test_ut_cap_002_for_entity_maps_known_entities(): void
    {
        $this->assertSame(Capabilities::MANAGE, Capabilities::for_entity('goal'));
        $this->assertSame(Capabilities::MODERATE, Capabilities::for_entity('contribution'));
        $this->assertSame(Capabilities::PUBLISH_GUIDES, Capabilities::for_entity('guide'));
        $this->assertSame(Capabilities::VIEW_EVENTS, Capabilities::for_entity('event'));
    }

    public function test_ut_cap_002_for_entity_defaults_to_manage_for_unknown(): void
    {
        $this->assertSame(Capabilities::MANAGE, Capabilities::for_entity('unknown'));
        $this->assertSame(Capabilities::MANAGE, Capabilities::for_entity(''));
    }
}
