<?php
/**
 * Base test case for the Common Goals unit suite.
 *
 * Sets up Brain Monkey, restores superglobals and provides common WordPress
 * function stubs that tests can override with Brain\Monkey\Functions\when().
 *
 * @package CommonGoals\Tests\Unit\Support
 */

namespace CommonGoals\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Unit test case with sane defaults for isolated WordPress-bound testing.
 */
abstract class UnitTestCase extends TestCase
{
    protected WpdbSpy $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->wpdb = new WpdbSpy();
        $GLOBALS['wpdb'] = $this->wpdb;

        $_GET = [];
        $_POST = [];
        $_SERVER = ['REMOTE_ADDR' => '203.0.113.7'];

        $this->register_default_stubs();
    }

    protected function tearDown(): void
    {
        unset($_GET, $_POST);
        $_SERVER = [];

        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Registers harmless default stubs. Individual tests may override any of
     * these by calling Functions\when(...) again in their own setUp/data setup.
     */
    protected function register_default_stubs(): void
    {
        Functions\when('absint')->alias(static fn($v) => (int) abs((int) $v));
        Functions\when('sanitize_key')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_title')->alias(static fn($value) => strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $value)));
        Functions\when('wp_unslash')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('wp_strip_all_tags')->alias(static fn($v) => strip_tags((string) $v));
        Functions\when('wp_trim_words')->alias(static function ($text, $count = 30) {
            $words = explode(' ', (string) $text);
            return implode(' ', array_slice($words, 0, $count));
        });
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('__')->returnArg();
        Functions\when('current_time')->justReturn('2026-07-26 12:00:00');
        Functions\when('get_current_user_id')->justReturn(0);
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_option')->alias(static fn($name, $default = false) => $default);
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('home_url')->alias(static fn($path = '') => 'https://example.test' . ($path ?? ''));
        Functions\when('admin_url')->alias(static fn($path = '') => 'https://example.test/wp-admin/' . ($path ?? ''));
        Functions\when('wp_rand')->alias(static fn($min = 0, $max = 0) => $min);
        Functions\when('wp_json_encode')->alias(static fn($value, $options = 0) => json_encode($value, $options));
        Functions\when('checked')->justReturn('');
        Functions\when('plugin_basename')->returnArg();
        Functions\when('plugin_dir_path')->alias(static fn($file) => dirname($file) . '/');
        Functions\when('plugin_dir_url')->alias(static fn($file) => 'https://example.test/wp-content/plugins/common-goals/');
        Functions\when('trailingslashit')->alias(static fn($value) => rtrim((string) $value, '/') . '/');

        $this->stub_noop([
            'load_plugin_textdomain',
            'register_activation_hook',
            'add_shortcode',
            'wp_register_style',
            'wp_register_script',
            'wp_enqueue_style',
            'wp_enqueue_script',
            'wp_localize_script',
            'register_block_type',
            'register_rest_route',
            'wp_die',
            'check_admin_referer',
            'nocache_headers',
            'status_header',
            'get_header',
            'wp_reset_postdata',
            'register_setting',
            'add_settings_section',
            'add_settings_field',
            'add_submenu_page',
            'wp_add_privacy_policy_content',
            'add_role',
            'remove_role',
            'remove_cap',
        ]);
        Functions\when('shortcode_atts')->alias(static function ($defaults, $attributes, $shortcode = '') {
            return array_merge($defaults, is_array($attributes) ? $attributes : []);
        });
        Functions\when('wp_safe_redirect')->justReturn();
        Functions\when('add_query_arg')->alias(static function ($key, $value = '', $url = '') {
            if (is_array($key)) {
                $params = $key;
                $base = $value === '' ? '' : $value;
            } else {
                $params = [$key => $value];
                $base = $url === '' ? '' : $url;
            }
            $query = http_build_query($params);
            return $query !== '' ? $base . (str_contains($base, '?') ? '&' : '?') . $query : $base;
        });
        Functions\when('wp_get_referer')->justReturn('https://example.test/wp-admin/admin.php?page=common-goals');
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('get_bloginfo')->justReturn('Test Site');
        Functions\when('get_users')->justReturn([]);
        Functions\when('get_userdata')->justReturn(false);
        Functions\when('get_user_by')->justReturn(false);
        Functions\when('get_role')->justReturn(null);
        Functions\when('wp_mail')->justReturn(true);
        Functions\when('get_query_var')->justReturn('');
        Functions\when('rest_url')->alias(static fn($path = '') => 'https://example.test/wp-json/' . (string) $path);
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('wp_create_nonce')->justReturn('stubnonce');
        Functions\when('wp_login_url')->alias(static fn($redirect = '') => 'https://example.test/wp-login.php');
        Functions\when('number_format_i18n')->alias(static fn($n, $d = 0) => number_format((float) $n, $d));
        Functions\when('mysql2date')->alias(static fn($format, $date) => $date);
        Functions\when('wp_kses')->alias(static function ($string, array $tags = []) {
            // Minimal: strip <script>. Keep everything else for assertions.
            return preg_replace('#<script.*?</script>#is', '', (string) $string);
        });
        Functions\when('wpautop')->returnArg();
        Functions\when('get_stylesheet_directory')->justReturn('/tmp/child-theme');
        Functions\when('get_template_directory')->justReturn('/tmp/parent-theme');
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_event')->justReturn(true);
        Functions\when('wp_unschedule_event')->justReturn(true);
        Functions\when('add_rewrite_rule')->justReturn();
        Functions\when('flush_rewrite_rules')->justReturn();
    }

    /**
     * Registers a list of WordPress functions as no-ops.
     *
     * @param array<int, string> $functions
     */
    private function stub_noop(array $functions): void
    {
        foreach ($functions as $function) {
            Functions\when($function)->justReturn();
        }
    }

    /**
     * Asserts that at least one recorded SQL call contains all needles.
     *
     * @param array<int, string>|string $needles
     */
    protected function assertSqlContainsInOneCall($needles, string $message = ''): void
    {
        $needles = (array) $needles;
        foreach ($this->wpdb->sql_strings() as $sql) {
            $sqlLower = strtolower($sql);
            $matched = true;
            foreach ($needles as $needle) {
                if (! str_contains($sqlLower, strtolower($needle))) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                $this->addToAssertionCount(1);
                return;
            }
        }
        $this->fail($message ?: 'No single SQL call contained all needles: ' . implode(', ', $needles));
    }
}
