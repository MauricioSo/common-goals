<?php
/**
 * Unit tests for EventLogger.
 *
 * Covers spec case UT-EVT-001.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use Brain\Monkey\Functions;
use CommonGoals\EventLogger;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class EventLoggerUnitTest extends UnitTestCase
{
    public function test_ut_evt_001_log_inserts_row_with_user_and_time(): void
    {
        Functions\when('get_current_user_id')->justReturn(7);

        $result = EventLogger::log('contribution', 42, 'contribution.created', ['goal_id' => 1]);

        $this->assertTrue($result);
        $this->assertSame(1, $this->wpdb->count_method('insert'));
        $insert = $this->wpdb->calls[0];
        $this->assertSame('wp_cg_events', $insert['sql']);
        $data = $insert['extra']['data'];
        $this->assertSame('contribution', $data['object_type']);
        $this->assertSame(42, $data['object_id']);
        $this->assertSame('contribution.created', $data['event_type']);
        $this->assertSame(7, $data['created_by']);
        $this->assertJsonStringEqualsJsonString('{"goal_id":1}', $data['event_data']);
    }

    public function test_ut_evt_001_empty_event_data_is_stored_as_null(): void
    {
        $result = EventLogger::log('goal', 1, 'goal.created');

        $this->assertTrue($result);
        $data = $this->wpdb->calls[0]['extra']['data'];
        $this->assertNull($data['event_data']);
    }

    public function test_ut_evt_001_returns_false_on_database_failure(): void
    {
        $this->wpdb->queue_insert(false);

        $result = EventLogger::log('goal', 1, 'goal.created');

        $this->assertFalse($result);
    }
}
