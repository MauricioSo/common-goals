<?php
/**
 * Tests for status transitions, capabilities and export structure.
 *
 * @package CommonGoals
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use CommonGoals\Domain;
use CommonGoals\Capabilities;
use CommonGoals\Exporter;

class Phase2Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_valid_transition_pending_to_open(): void
    {
        $this->assertTrue(Domain::is_valid_transition('pending', 'open'));
    }

    public function test_valid_transition_open_to_resolved(): void
    {
        $this->assertTrue(Domain::is_valid_transition('open', 'resolved'));
    }

    public function test_valid_transition_resolved_to_spam_is_not_allowed(): void
    {
        $this->assertFalse(Domain::is_valid_transition('resolved', 'spam'));
    }

    public function test_same_status_is_always_valid(): void
    {
        $this->assertTrue(Domain::is_valid_transition('open', 'open'));
        $this->assertTrue(Domain::is_valid_transition('pending', 'pending'));
    }

    public function test_transition_from_unknown_status_returns_false(): void
    {
        $this->assertFalse(Domain::is_valid_transition('bogus', 'open'));
    }

    public function test_response_statuses_include_pending_and_spam(): void
    {
        $statuses = Domain::response_statuses();

        $this->assertContains('pending', $statuses);
        $this->assertContains('published', $statuses);
        $this->assertContains('spam', $statuses);
        $this->assertContains('hidden', $statuses);
    }

    public function test_public_response_statuses_only_published(): void
    {
        $this->assertSame(['published'], Domain::public_response_statuses());
    }

    public function test_capabilities_constants_are_distinct(): void
    {
        $this->assertNotEquals(Capabilities::MANAGE, Capabilities::MODERATE);
        $this->assertNotEquals(Capabilities::MODERATE, Capabilities::PUBLISH_GUIDES);
        $this->assertNotEquals(Capabilities::PUBLISH_GUIDES, Capabilities::VIEW_EVENTS);
    }

    public function test_for_entity_returns_correct_capability(): void
    {
        $this->assertSame(Capabilities::MANAGE, Capabilities::for_entity('goal'));
        $this->assertSame(Capabilities::MODERATE, Capabilities::for_entity('contribution'));
        $this->assertSame(Capabilities::PUBLISH_GUIDES, Capabilities::for_entity('guide'));
        $this->assertSame(Capabilities::VIEW_EVENTS, Capabilities::for_entity('event'));
    }

    public function test_for_entity_unknown_returns_manage(): void
    {
        $this->assertSame(Capabilities::MANAGE, Capabilities::for_entity('unknown'));
    }

    public function test_export_schema_version_is_string(): void
    {
        $this->assertIsString(Exporter::SCHEMA_VERSION);
        $this->assertNotEmpty(Exporter::SCHEMA_VERSION);
    }
}
