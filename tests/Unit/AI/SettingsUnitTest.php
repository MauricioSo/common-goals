<?php
/**
 * Unit tests for the AI Settings accessor.
 *
 * @package CommonGoals\Tests\Unit\AI
 */

namespace CommonGoals\Tests\Unit\AI;

use Brain\Monkey\Functions;
use CommonGoals\AI\Settings;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class SettingsUnitTest extends UnitTestCase
{
    public function test_defaults_contain_expected_keys_and_mvp_flows_enabled(): void
    {
        $defaults = Settings::defaults();

        $this->assertSame('deepseek-v4-flash', $defaults['model']);
        $this->assertSame('https://api.deepseek.com', $defaults['base_url']);
        $this->assertTrue($defaults['enabled_flows']['discover']);
        $this->assertTrue($defaults['enabled_flows']['summarize']);
        $this->assertFalse($defaults['enabled_flows']['moderate']);
        $this->assertFalse($defaults['enabled_flows']['guide']);
    }

    public function test_all_merges_stored_options_over_defaults(): void
    {
        Functions\when('get_option')->alias(static function ($name, $default = false) {
            if ($name === Settings::OPTION_NAME) {
                return ['model' => 'custom-model', 'enabled_flows' => ['organize' => true]];
            }
            return $default;
        });

        $all = Settings::all();

        $this->assertSame('custom-model', $all['model']);
        $this->assertTrue($all['enabled_flows']['organize']);
        $this->assertTrue($all['enabled_flows']['discover'], 'Unspecified flows must keep their default state');
        $this->assertSame('https://api.deepseek.com', $all['base_url']);
    }

    public function test_masked_api_key_hides_middle_of_the_key(): void
    {
        Functions\when('get_option')->alias(static function ($name, $default = false) {
            return $name === Settings::OPTION_NAME ? ['api_key' => 'sk-1234567890abcdef'] : $default;
        });

        $masked = Settings::masked_api_key();

        // 19-char key: first 4 + (19-8=11) stars + last 4.
        $this->assertSame('sk-1***********cdef', $masked);
        $this->assertStringNotContainsString('1234567890', $masked);
    }

    public function test_is_configured_reflects_presence_of_key(): void
    {
        Functions\when('get_option')->justReturn(false);

        $this->assertFalse(Settings::is_configured());

        Functions\when('get_option')->alias(static fn($n, $d = false) => $n === Settings::OPTION_NAME ? ['api_key' => 'fixture-key'] : $d);

        $this->assertTrue(Settings::is_configured());
    }

    public function test_flow_meta_returns_defaults_for_unknown_flow(): void
    {
        $meta = Settings::flow_meta('does_not_exist');

        $this->assertFalse($meta['enabled']);
    }

    public function test_flow_ids_contains_all_seven_flows(): void
    {
        $this->assertSame(
            ['discover', 'compose', 'answer', 'summarize', 'organize', 'moderate', 'guide'],
            Settings::flow_ids()
        );
    }
}
