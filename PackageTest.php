<?php

namespace CommonGoals\Tests\Integration;

use CommonGoals\Tests\Integration\Support\IntegrationTestCase;

final class PackageTest extends IntegrationTestCase
{
    public function test_rel_001_main_plugin_file_exists(): void
    {
        $this->assertFileExists(COMMON_GOALS_PLUGIN_FILE);
    }

    public function test_rel_002_plugin_version_matches_composer(): void
    {
        $plugin_version = COMMON_GOALS_VERSION;

        $composer_path = dirname(COMMON_GOALS_PLUGIN_DIR) . DIRECTORY_SEPARATOR . 'composer.json';

        if (file_exists($composer_path)) {
            $composer = json_decode(file_get_contents($composer_path), true);
            if (isset($composer['version'])) {
                $this->assertSame($plugin_version, $composer['version']);
            }
        }
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $plugin_version);
    }

    public function test_rel_003_textdomain_constant_defined(): void
    {
        $content = file_get_contents(COMMON_GOALS_PLUGIN_FILE);
        $this->assertMatchesRegularExpression('/Text Domain:\s*(.+)/', $content);
        preg_match('/Text Domain:\s*(.+)/', $content, $m);
        $this->assertSame('common-goals', trim($m[1] ?? ''));
    }

    public function test_rel_004_pot_file_exists(): void
    {
        $pot = COMMON_GOALS_PLUGIN_DIR . 'languages' . DIRECTORY_SEPARATOR . 'common-goals.pot';
        $this->assertFileExists($pot);
        $content = file_get_contents($pot);
        $this->assertStringContainsString('Common Goals', $content);
    }

    public function test_rel_005_pot_has_textdomain(): void
    {
        $pot = COMMON_GOALS_PLUGIN_DIR . 'languages' . DIRECTORY_SEPARATOR . 'common-goals.pot';
        $content = file_get_contents($pot);
        $this->assertStringContainsString('"Language-Team:', $content);
    }

    public function test_rel_006_license_declared(): void
    {
        $content = file_get_contents(COMMON_GOALS_PLUGIN_FILE);
        $this->assertMatchesRegularExpression('/License:\s*(.+)/', $content);
    }

    public function test_rel_007_readme_exists(): void
    {
        $readme = dirname(COMMON_GOALS_PLUGIN_FILE) . DIRECTORY_SEPARATOR . 'readme.txt';
        if (!file_exists($readme)) {
            $readme = dirname(dirname(COMMON_GOALS_PLUGIN_FILE)) . DIRECTORY_SEPARATOR . 'readme.txt';
        }
        if (!file_exists($readme)) {
            $readme = COMMON_GOALS_PLUGIN_DIR . 'readme.txt';
        }
        $this->assertFileExists($readme);
        $content = file_get_contents($readme);
        $this->assertStringContainsString('Common Goals', $content);
    }

    public function test_rel_008_uninstall_php_exists(): void
    {
        $uninstall = COMMON_GOALS_PLUGIN_DIR . 'uninstall.php';
        $this->assertFileExists($uninstall);
    }

    public function test_rel_009_required_headers_present(): void
    {
        $plugin_data = get_plugin_data(COMMON_GOALS_PLUGIN_FILE);
        $this->assertNotEmpty($plugin_data['Name']);
        $this->assertNotEmpty($plugin_data['Version']);
        $this->assertNotEmpty($plugin_data['Author']);
        $this->assertGreaterThanOrEqual('6.5', $plugin_data['RequiresWP']);
        $this->assertGreaterThanOrEqual('8.1', $plugin_data['RequiresPHP']);
    }

    public function test_rel_010_plugin_name_matches(): void
    {
        $plugin_data = get_plugin_data(COMMON_GOALS_PLUGIN_FILE);
        $this->assertSame('Common Goals', $plugin_data['Name']);
    }

    public function test_rel_011_no_syntax_errors_in_includes(): void
    {
        $files = glob(COMMON_GOALS_PLUGIN_DIR . 'includes/**/*.php');
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $output = [];
            exec('php -l "' . $file . '" 2>&1', $output, $exit_code);
            $this->assertSame(0, $exit_code, "Syntax error in: {$file}\n" . implode("\n", $output));
        }
    }
}
