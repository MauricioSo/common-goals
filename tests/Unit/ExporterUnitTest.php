<?php
/**
 * Unit tests for Exporter payload structure and manifest.
 *
 * Covers spec case UT-EXP-001.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use CommonGoals\Exporter;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class ExporterUnitTest extends UnitTestCase
{
    public function test_ut_exp_001_schema_version_is_stable(): void
    {
        $this->assertSame('1.0', Exporter::SCHEMA_VERSION);
    }

    public function test_ut_exp_001_build_export_contains_seven_tables_and_manifest(): void
    {
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_results([]);
        // manifest counts: 7 get_var calls.
        foreach (range(1, 7) as $i) {
            $this->wpdb->queue_get_var('0');
        }

        $export = Exporter::build_export();

        $this->assertSame('1.0', $export['schema_version']);
        $this->assertSame(COMMON_GOALS_VERSION, $export['plugin_version']);
        $this->assertArrayHasKey('tables', $export);
        $this->assertArrayHasKey('manifest', $export);
        $tables = $export['tables'];
        $this->assertSame(['communities', 'members', 'goals', 'contributions', 'responses', 'guides', 'events'], array_keys($tables));
        $this->assertArrayHasKey('table_counts', $export['manifest']);
        $this->assertArrayHasKey('relationships', $export['manifest']);
        $this->assertArrayHasKey('allowed_values', $export['manifest']);
    }

    public function test_ut_exp_001_to_json_returns_valid_json(): void
    {
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_results([]);
        foreach (range(1, 7) as $i) {
            $this->wpdb->queue_get_var('0');
        }

        $json = Exporter::to_json();

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('1.0', $decoded['schema_version']);
    }

    public function test_ut_exp_001_manifest_counts_match_domain_constants(): void
    {
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_results([]);
        $this->wpdb->queue_get_results([]);
        foreach (range(1, 7) as $i) {
            $this->wpdb->queue_get_var('0');
        }

        $export = Exporter::build_export();
        $allowed = $export['manifest']['allowed_values'];

        $this->assertSame(\CommonGoals\Domain::CONTRIBUTION_TYPES, $allowed['contribution_types']);
        $this->assertSame(\CommonGoals\Domain::CONTRIBUTION_STATUSES, $allowed['contribution_statuses']);
        $this->assertSame(\CommonGoals\Domain::GUIDE_STATUSES, $allowed['guide_statuses']);
    }
}
