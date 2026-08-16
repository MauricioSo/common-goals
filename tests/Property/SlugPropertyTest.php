<?php
/**
 * Property-based tests for guide slug uniqueness generation.
 *
 * Covers spec case PB-SLUG-001. Uses reflection on the private
 * GuidesAdminPage::create_unique_guide_slug helper, as permitted by the spec.
 *
 * @package CommonGoals\Tests\Property
 */

namespace CommonGoals\Tests\Property;

use CommonGoals\Admin\GuidesAdminPage;
use CommonGoals\Tests\Property\Support\PropertyRng;
use CommonGoals\Tests\Property\Support\PropertyTestCase;

final class SlugPropertyTest extends PropertyTestCase
{
    public function test_pb_slug_001_slug_is_non_empty_and_simplified_without_collisions(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            $page = new GuidesAdminPage();
            $method = new \ReflectionMethod($page, 'create_unique_guide_slug');
            $method->setAccessible(true);
            $source = $rng->string($rng->between(0, 20), 'A-Za-z .,_-');

            // No collisions: get_var returns null (default) on the first probe.
            $slug = $method->invoke($page, $source, $rng->between(1, 5000));

            $this->assertNotSame('', $slug, 'Slug must never be empty');
            // No uppercase or whitespace survives sanitize_title.
            $this->assertDoesNotMatchRegularExpression('/[A-Z\s]/', $slug);
        }, 300, 'PB-SLUG-001');
    }

    public function test_pb_slug_001_first_collision_appends_suffix_two(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            $page = new GuidesAdminPage();
            $method = new \ReflectionMethod($page, 'create_unique_guide_slug');
            $method->setAccessible(true);
            $source = $rng->string($rng->between(0, 12), 'abcdefghijklmnopqrstuvwxyz');

            // First probe collides, second does not => slug becomes {base}-2.
            $this->wpdb->queue_get_var('1');
            $this->wpdb->queue_get_var(null);

            $slug = $method->invoke($page, $source, 7);

            // Empty sources fall back to the 'guide' base slug.
            $expected_base = $source !== '' ? $source : 'guide';
            $this->assertSame($expected_base . '-2', $slug, "First collision must yield '{base}-2' for source='{$source}'");
        }, 100, 'PB-SLUG-001-collision');
    }
}
