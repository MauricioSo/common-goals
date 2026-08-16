<?php
/**
 * Property-based tests for EventLogger and Exporter serialization invariants.
 *
 * Covers spec cases PB-SER-001 and PB-SER-002.
 *
 * @package CommonGoals\Tests\Property
 */

namespace CommonGoals\Tests\Property;

use Brain\Monkey\Functions;
use CommonGoals\EventLogger;
use CommonGoals\Exporter;
use CommonGoals\Tests\Property\Support\PropertyRng;
use CommonGoals\Tests\Property\Support\PropertyTestCase;

final class SerializationPropertyTest extends PropertyTestCase
{
    public function test_pb_ser_001_event_data_round_trips_or_null_when_empty(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            Functions\when('get_current_user_id')->justReturn($rng->between(0, 100));

            $size = $rng->between(0, 5);
            $data = [];
            for ($i = 0; $i < $size; $i++) {
                $data['k' . $i] = $rng->element(['a', 1, true, null, ['nested']]);
            }

            EventLogger::log('goal', $rng->between(1, 99), 'goal.created', $data);

            $insert = $this->wpdb->calls[0];
            $stored = $insert['extra']['data']['event_data'];

            if ($data === []) {
                $this->assertNull($stored, 'Empty event_data must be stored as null');
            } else {
                $this->assertSame($data, json_decode($stored, true), 'Event data must round-trip through JSON');
            }
            // Identity and time must not mix into the payload key set.
            if ($data !== []) {
                $decoded = json_decode($stored, true);
                $this->assertArrayNotHasKey('created_by', $decoded);
                $this->assertArrayNotHasKey('created_at', $decoded);
            }
        }, 200, 'PB-SER-001');
    }

    public function test_pb_ser_002_export_manifest_counts_match_table_arrays(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            // Exporter consumes get_results in tables order and get_var in
            // manifest order (goals, communities, members, contributions, ...).
            $table_keys = ['communities', 'members', 'goals', 'contributions', 'responses', 'guides', 'events'];
            $manifest_order = ['goals', 'communities', 'members', 'contributions', 'responses', 'guides', 'events'];
            $counts_by_key = [];
            foreach ($table_keys as $key) {
                $n = $rng->between(0, 20);
                $counts_by_key[$key] = $n;
                $rows = [];
                for ($j = 0; $j < $n; $j++) {
                    $rows[] = ['id' => $j + 1];
                }
                $this->wpdb->queue_get_results($rows);
            }
            // Queue manifest get_var in the order build_manifest consumes them.
            foreach ($manifest_order as $key) {
                $this->wpdb->queue_get_var((string) $counts_by_key[$key]);
            }

            $export = Exporter::build_export();

            $this->assertSame('1.0', $export['schema_version']);
            $this->assertSame($table_keys, array_keys($export['tables']));
            foreach ($table_keys as $key) {
                $this->assertCount($counts_by_key[$key], $export['tables'][$key], "Table {$key} row count mismatch");
                $this->assertSame($counts_by_key[$key], $export['manifest']['table_counts'][$key], "Manifest count for {$key} must match array");
            }
        }, 150, 'PB-SER-002');
    }
}
