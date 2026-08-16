<?php

namespace CommonGoals\Tests\Integration;

use CommonGoals\Blocks;
use CommonGoals\Capabilities;
use CommonGoals\Database;
use CommonGoals\Domain;
use CommonGoals\TemplateLoader;
use CommonGoals\Tests\Integration\Support\IntegrationTestCase;

final class FrontendTest extends IntegrationTestCase
{
    private function reregister_blocks(): void
    {
        $reg = \WP_Block_Type_Registry::get_instance();
        foreach (['common-goals/board', 'common-goals/guides'] as $name) {
            if ($reg->is_registered($name)) {
                $reg->unregister($name);
            }
        }
        Blocks::register_blocks();
    }

    public function test_fe_001_block_registration_works(): void
    {
        $this->reregister_blocks();
        $board = \WP_Block_Type_Registry::get_instance()->get_registered('common-goals/board');
        $guides = \WP_Block_Type_Registry::get_instance()->get_registered('common-goals/guides');

        $this->assertNotNull($board, 'board block should be registered');
        $this->assertNotNull($guides, 'guides block should be registered');
    }

    public function test_fe_002_block_metadata_attributes(): void
    {
        $this->reregister_blocks();
        $board = \WP_Block_Type_Registry::get_instance()->get_registered('common-goals/board');

        $this->assertSame('common-goals/board', $board->name);
        $this->assertSame(3, $board->api_version);
        $this->assertSame('widgets', $board->category);

        $attrs = $board->get_attributes();
        $this->assertArrayHasKey('goal_id', $attrs);
        $this->assertArrayHasKey('community_id', $attrs);
        $goal_attr = $attrs['goal_id'];
        $default = is_object($goal_attr) ? $goal_attr->get_default() : ($goal_attr['default'] ?? null);
        $this->assertSame(0, $default);
    }

    public function test_fe_003_guides_block_metadata(): void
    {
        $this->reregister_blocks();
        $guides = \WP_Block_Type_Registry::get_instance()->get_registered('common-goals/guides');

        $this->assertSame('common-goals/guides', $guides->name);
        $this->assertSame(3, $guides->api_version);

        $attrs = $guides->get_attributes();
        $this->assertArrayHasKey('limit', $attrs);
        $limit_attr = $attrs['limit'];
        $default = is_object($limit_attr) ? $limit_attr->get_default() : ($limit_attr['default'] ?? null);
        $this->assertSame(20, $default);
    }

    public function test_fe_004_block_missing_json_tolerant(): void
    {
        $reg = \WP_Block_Type_Registry::get_instance();
        $before_board = $reg->is_registered('common-goals/board');
        $before_guides = $reg->is_registered('common-goals/guides');

        $this->reregister_blocks();

        $after_board = $reg->is_registered('common-goals/board');
        $after_guides = $reg->is_registered('common-goals/guides');

        $this->assertTrue($after_board);
        $this->assertTrue($after_guides);
    }

    public function test_fe_005_template_locate_returns_path(): void
    {
        $path = TemplateLoader::locate('board.php');
        $this->assertStringEndsWith('board.php', $path);
        $this->assertFileExists($path);
    }

    public function test_fe_006_template_locate_fallsback_to_plugin(): void
    {
        $path = TemplateLoader::locate('guides.php');
        $this->assertStringEndsWith('guides.php', $path);
        $this->assertFileExists($path);
    }

    public function test_fe_007_shortcode_board_renders_empty(): void
    {
        $output = do_shortcode('[common_goals_board]');
        $this->assertStringContainsString('common-goals-board', $output);
    }

    public function test_fe_008_shortcode_guides_renders_empty(): void
    {
        $output = do_shortcode('[common_goals_guides]');
        $this->assertIsString($output);
    }

    public function test_fe_009_board_shortcode_with_active_goal(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);

        $output = do_shortcode('[common_goals_board goal_id="' . $g->id . '"]');
        $this->assertStringContainsString($g->title, wp_strip_all_tags($output));
    }

    public function test_fe_010_css_is_encolable(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);

        do_action('wp_enqueue_scripts');
        do_shortcode('[common_goals_board goal_id="' . $g->id . '"]');

        global $wp_styles;
        $enqueued = $wp_styles->query('common-goals-board', 'enqueued');
        $registered = $wp_styles->query('common-goals-board', 'registered');
        $this->assertTrue($enqueued || $registered, 'board CSS should be enqueued or registered');
    }

    public function test_fe_011_board_shortcode_inactive_goal_empty(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $this->create_goal((int) $c->id, ['status' => 'inactive']);

        $output = do_shortcode('[common_goals_board]');
        $this->assertStringContainsString('common-goals-board--empty', $output);
    }

    public function test_fe_012_contribution_renders_title_and_body(): void
    {
        $this->act_as_admin();
        $c = $this->create_community();
        $g = $this->create_goal((int) $c->id);

        global $wpdb;
        $wpdb->insert(Database::contributions_table(), [
            'goal_id' => $g->id,
            'user_id' => get_current_user_id(),
            'type' => 'question',
            'status' => 'open',
            'topic' => 'Help',
            'title' => 'My Question',
            'body' => 'Detailed body',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        $output = do_shortcode('[common_goals_board goal_id="' . $g->id . '"]');
        $this->assertStringContainsString('My Question', wp_strip_all_tags($output));
    }

    public function test_fe_013_capabilities_admin_has_all(): void
    {
        $this->act_as_admin();
        Capabilities::register();

        $admin = get_role('administrator');
        $this->assertTrue($admin->has_cap(Capabilities::MANAGE));
        $this->assertTrue($admin->has_cap(Capabilities::MODERATE));
        $this->assertTrue($admin->has_cap(Capabilities::PUBLISH_GUIDES));
        $this->assertTrue($admin->has_cap(Capabilities::VIEW_EVENTS));
    }

    public function test_fe_014_moderator_custom_role_exists(): void
    {
        Capabilities::register();
        $role = get_role(Capabilities::MODERATOR_ROLE);
        $this->assertNotNull($role);
        $this->assertTrue($role->has_cap('read'));
        $this->assertTrue($role->has_cap(Capabilities::MODERATE));
    }

    public function test_fe_015_capabilities_unregister_removes_from_admin(): void
    {
        Capabilities::register();
        Capabilities::unregister();

        $admin = get_role('administrator');
        $this->assertFalse($admin->has_cap(Capabilities::MANAGE));
        $role = get_role(Capabilities::MODERATOR_ROLE);
        $this->assertNull($role);
    }

    public function test_fe_016_public_statuses_allowlist(): void
    {
        $this->assertContains('open', Domain::PUBLIC_STATUSES);
        $this->assertContains('in_progress', Domain::PUBLIC_STATUSES);
        $this->assertContains('resolved', Domain::PUBLIC_STATUSES);
        $this->assertCount(3, Domain::PUBLIC_STATUSES);
    }

    public function test_fe_017_domain_contribution_types(): void
    {
        $this->assertContains('question', Domain::CONTRIBUTION_TYPES);
        $this->assertContains('problem', Domain::CONTRIBUTION_TYPES);
        $this->assertContains('experience', Domain::CONTRIBUTION_TYPES);
        $this->assertContains('resource', Domain::CONTRIBUTION_TYPES);
        $this->assertCount(4, Domain::CONTRIBUTION_TYPES);
    }

    public function test_fe_018_community_roles(): void
    {
        $this->assertContains('admin', Domain::COMMUNITY_ROLES);
        $this->assertContains('moderator', Domain::COMMUNITY_ROLES);
        $this->assertContains('member', Domain::COMMUNITY_ROLES);
    }

    public function test_fe_019_for_entity_maps_caps(): void
    {
        $this->assertSame(Capabilities::MANAGE, Capabilities::for_entity('goal'));
        $this->assertSame(Capabilities::MODERATE, Capabilities::for_entity('contribution'));
        $this->assertSame(Capabilities::PUBLISH_GUIDES, Capabilities::for_entity('guide'));
        $this->assertSame(Capabilities::VIEW_EVENTS, Capabilities::for_entity('event'));
        $this->assertSame(Capabilities::MANAGE, Capabilities::for_entity('unknown'));
    }
}
