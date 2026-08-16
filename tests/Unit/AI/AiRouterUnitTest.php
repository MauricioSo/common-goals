<?php
/**
 * Unit tests for the AI REST router.
 *
 * @package CommonGoals\Tests\Unit\AI
 */

namespace CommonGoals\Tests\Unit\AI;

use Brain\Monkey\Functions;
use CommonGoals\AI\AiRouter;
use CommonGoals\AI\Settings;
use CommonGoals\Tests\Unit\Support\RequestStub;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class AiRouterUnitTest extends UnitTestCase
{
    public function test_register_routes_registers_seven_flows_and_status(): void
    {
        $routes = [];
        Functions\when('register_rest_route')->alias(static function ($ns, $route, $args) use (&$routes) {
            $routes[] = $ns . $route . ':' . $args['methods'];
        });

        AiRouter::register_routes();

        $this->assertContains('common-goals/v1/ai/discover:POST', $routes);
        $this->assertContains('common-goals/v1/ai/compose:POST', $routes);
        $this->assertContains('common-goals/v1/ai/answer:POST', $routes);
        $this->assertContains('common-goals/v1/ai/summarize:POST', $routes);
        $this->assertContains('common-goals/v1/ai/organize:POST', $routes);
        $this->assertContains('common-goals/v1/ai/moderate:POST', $routes);
        $this->assertContains('common-goals/v1/ai/guide:POST', $routes);
        $this->assertContains('common-goals/v1/ai/status:GET', $routes);
        $this->assertCount(8, $routes);
    }

    public function test_status_returns_flow_availability_and_budget(): void
    {
        Functions\when('get_option')->alias(static function ($name, $default = false) {
            if ($name === Settings::OPTION_NAME) {
                return array_merge(Settings::defaults(), ['api_key' => 'fixture-key']);
            }
            return $default;
        });
        Functions\when('apply_filters')->returnArg(2);
        $this->wpdb->queue_get_var(1.25);

        $response = AiRouter::status(new RequestStub([]));
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['configured']);
        $this->assertTrue($data['flows']['discover']['enabled']);
        $this->assertFalse($data['flows']['moderate']['enabled']);
        $this->assertSame(1.25, $data['budget']['spent_usd']);
    }

    public function test_read_discover_sanitizes_input(): void
    {
        $input = AiRouter::read_discover(new RequestStub([
            'query'        => 'hello',
            'goal_id'      => '12',
            'community_id' => '3',
        ]));

        $this->assertSame('hello', $input['query']);
        $this->assertSame(12, $input['goal_id']);
        $this->assertSame(3, $input['community_id']);
    }

    public function test_read_organize_maps_ids_array(): void
    {
        $input = AiRouter::read_organize(new RequestStub([
            'contribution_ids' => ['1', '2', 'abc', '3'],
            'community_id'     => '7',
        ]));

        $this->assertSame([1, 2, 0, 3], $input['contribution_ids']);
        $this->assertSame(7, $input['community_id']);
    }
}
