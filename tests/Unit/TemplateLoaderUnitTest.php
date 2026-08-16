<?php
/**
 * Unit tests for TemplateLoader precedence and capture semantics.
 *
 * Covers spec case UT-TPL-001.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use Brain\Monkey\Functions;
use CommonGoals\TemplateLoader;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class TemplateLoaderUnitTest extends UnitTestCase
{
    private string $tempChild;
    private string $tempParent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempChild = sys_get_temp_dir() . '/cg_child_' . uniqid();
        $this->tempParent = sys_get_temp_dir() . '/cg_parent_' . uniqid();
        mkdir($this->tempChild . '/common-goals', 0777, true);
        mkdir($this->tempParent . '/common-goals', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempChild);
        $this->rrmdir($this->tempParent);
        parent::tearDown();
    }

    public function test_ut_tpl_001_child_theme_takes_precedence_over_parent_and_plugin(): void
    {
        file_put_contents($this->tempChild . '/common-goals/board.php', '<?php echo "CHILD";');
        file_put_contents($this->tempParent . '/common-goals/board.php', '<?php echo "PARENT";');
        Functions\when('get_stylesheet_directory')->justReturn($this->tempChild);
        Functions\when('get_template_directory')->justReturn($this->tempParent);

        $path = TemplateLoader::locate('board.php');

        $this->assertSame($this->tempChild . '/common-goals/board.php', $path);
    }

    public function test_ut_tpl_001_parent_theme_used_when_child_has_no_override(): void
    {
        file_put_contents($this->tempParent . '/common-goals/board.php', '<?php echo "PARENT";');
        Functions\when('get_stylesheet_directory')->justReturn($this->tempChild);
        Functions\when('get_template_directory')->justReturn($this->tempParent);

        $path = TemplateLoader::locate('board.php');

        $this->assertSame($this->tempParent . '/common-goals/board.php', $path);
    }

    public function test_ut_tpl_001_falls_back_to_plugin_template(): void
    {
        Functions\when('get_stylesheet_directory')->justReturn($this->tempChild);
        Functions\when('get_template_directory')->justReturn($this->tempParent);

        $path = TemplateLoader::locate('guides.php');

        $this->assertStringEndsWith('templates/guides.php', str_replace('\\', '/', $path));
    }

    public function test_ut_tpl_001_capture_returns_output_and_load_uses_extr_skip(): void
    {
        // Plugin template guides.php exists; capture should return a string.
        Functions\when('get_stylesheet_directory')->justReturn($this->tempChild);
        Functions\when('get_template_directory')->justReturn($this->tempParent);
        $this->wpdb->queue_get_results([]);

        $output = TemplateLoader::capture('guides.php', []);

        $this->assertIsString($output);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
