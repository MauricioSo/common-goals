<?php
/**
 * Tests for Phase 10/11 multi-community integration touchpoints.
 *
 * @package CommonGoals
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use CommonGoals\RestApi;

class Phase10Test extends TestCase
{
    public function test_rest_api_exposes_communities_route(): void
    {
        $this->assertTrue(method_exists(RestApi::class, 'get_communities'));
    }

    public function test_board_block_declares_community_attribute(): void
    {
        $block_json = json_decode((string) file_get_contents(__DIR__ . '/../common-goals/assets/js/blocks/board/block.json'), true);

        $this->assertIsArray($block_json);
        $this->assertArrayHasKey('community_id', $block_json['attributes']);
        $this->assertSame('number', $block_json['attributes']['community_id']['type']);
    }

    public function test_uninstall_cleans_community_tables_when_cleanup_enabled(): void
    {
        $uninstall = (string) file_get_contents(__DIR__ . '/../common-goals/uninstall.php');

        $this->assertStringContainsString("cg_communities", $uninstall);
        $this->assertStringContainsString("cg_community_members", $uninstall);
    }

    public function test_exporter_includes_community_tables(): void
    {
        $exporter = (string) file_get_contents(__DIR__ . '/../common-goals/includes/Exporter.php');

        $this->assertStringContainsString("'communities'", $exporter);
        $this->assertStringContainsString("'members'", $exporter);
    }
}
