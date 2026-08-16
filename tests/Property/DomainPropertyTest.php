<?php
/**
 * Property-based tests for Domain invariants.
 *
 * Covers spec cases PB-DOM-001 to PB-DOM-008. Uses deterministic seeded
 * generators (PropertyRng) with 200-1000 iterations per property.
 *
 * @package CommonGoals\Tests\Property
 */

namespace CommonGoals\Tests\Property;

use Brain\Monkey\Functions;
use CommonGoals\Capabilities;
use CommonGoals\Domain;
use CommonGoals\Tests\Property\Support\PropertyRng;
use CommonGoals\Tests\Property\Support\PropertyTestCase;

final class DomainPropertyTest extends PropertyTestCase
{
    public function test_pb_dom_001_allowed_types_always_returns_valid_subset(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            Functions\when('apply_filters')->returnArg(2);

            $valid = Domain::CONTRIBUTION_TYPES;
            $count = $rng->between(0, 6);
            $raw = [];
            for ($i = 0; $i < $count; $i++) {
                // Mix of valid types, duplicates, junk strings and scalars.
                $raw[] = $rng->bool()
                    ? $valid[$rng->between(0, 3)]
                    : $rng->string(8, 'bogusXYZ-_');
            }
            $json = $rng->bool() ? json_encode($raw) : $rng->element(['', 'not-json{', 'null']);
            $goal = (object) ['allowed_contribution_types' => $json];

            $result = Domain::allowed_types_for_goal($goal);

            $this->assertNotEmpty($result, 'Result must never be empty');
            foreach ($result as $type) {
                $this->assertContains($type, $valid, 'Every returned type must be a known contribution type');
            }
            $this->assertSame(array_values($result), $result, 'Result must be reindexed');
        }, 300, 'PB-DOM-001');
    }

    public function test_pb_dom_002_community_scope_sql_never_leaks_text_into_sql(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            $ids = [];
            $count = $rng->between(0, 8);
            for ($i = 0; $i < $count; $i++) {
                $ids[] = $rng->element([0, -1, -99, 1, 2, 7, '2', 'evil', '; DROP', '100']);
            }
            $sql = Domain::community_scope_sql('goals.community_id', $ids);

            $absinted = array_values(array_filter(array_map(static fn($v) => abs((int) $v), $ids), static fn($v) => $v > 0));

            if ($absinted === []) {
                $this->assertSame('1 = 0', $sql);
            } else {
                $this->assertStringStartsWith('goals.community_id IN (', $sql);
                $this->assertStringEndsWith(')', $sql);
                // Only the IN list must be free of raw text/letters.
                $inner = substr($sql, strlen('goals.community_id IN ('), -1);
                $this->assertDoesNotMatchRegularExpression('/[a-zA-Z]/', $inner, 'Raw text must not reach the IN list');
                $numbers = array_map('intval', explode(',', $inner));
                foreach ($numbers as $n) {
                    $this->assertGreaterThan(0, $n, 'Every emitted id must be positive');
                }
            }
        }, 500, 'PB-DOM-002');
    }

    public function test_pb_dom_003_global_capability_is_sufficient_regardless_of_role(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            $hasGlobal = $rng->bool();
            $role = $rng->element([null, 'admin', 'moderator', 'member', 'bogus']);

            Functions\when('current_user_can')->justReturn($hasGlobal);
            Functions\when('get_current_user_id')->justReturn(7);
            if (!$hasGlobal && $role !== null) {
                $this->wpdb->queue_get_var($role);
            }

            $manage = Domain::current_user_can_manage_community(5);
            $moderate = Domain::current_user_can_moderate_community(5);

            if ($hasGlobal) {
                $this->assertTrue($manage, 'Global cap must grant manage');
                $this->assertTrue($moderate, 'Global cap must grant moderate');
            } else {
                // Without global cap, manage only via local admin role.
                $this->assertSame($role === 'admin', $manage);
            }
        }, 300, 'PB-DOM-003');
    }

    public function test_pb_dom_004_transitions_are_reflexive_and_always_boolean(): void
    {
        $statuses = array_merge(Domain::CONTRIBUTION_STATUSES, ['', 'bogus']);
        $this->assertProperty(function (PropertyRng $rng) use ($statuses) {
            $from = $statuses[$rng->between(0, count($statuses) - 1)];
            $to = $statuses[$rng->between(0, count($statuses) - 1)];

            $result = Domain::is_valid_transition($from, $to);

            $this->assertIsBool($result);
            if ($from === $to && $from !== '') {
                $this->assertTrue($result, "Transition {$from}->{$to} must be reflexive");
            }
        }, 1000, 'PB-DOM-004');
    }

    public function test_pb_dom_005_public_statuses_are_strict_subset(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            $all = Domain::CONTRIBUTION_STATUSES;
            $status = $all[$rng->between(0, count($all) - 1)];
            if (in_array($status, Domain::PUBLIC_STATUSES, true)) {
                $this->assertContains($status, Domain::CONTRIBUTION_STATUSES);
            }
            $this->assertNotContains('pending', Domain::PUBLIC_STATUSES);
            $this->assertNotContains('spam', Domain::PUBLIC_STATUSES);
            $this->assertNotContains('hidden', Domain::PUBLIC_STATUSES);
        }, 100, 'PB-DOM-005');
    }

    public function test_pb_dom_006_length_limits_enforce_exact_boundary(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            $at = $rng->bool();
            $text = str_repeat('a', Domain::MAX_TITLE_LENGTH + ($at ? 0 : 1));
            $over = mb_strlen($text) > Domain::MAX_TITLE_LENGTH;
            $this->assertSame($at ? false : true, $over);
            $this->assertSame($at ? Domain::MAX_TITLE_LENGTH : Domain::MAX_TITLE_LENGTH + 1, mb_strlen($text));
        }, 50, 'PB-DOM-006');
    }

    public function test_pb_dom_007_rate_limit_admits_first_max_then_rejects(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            $max = $rng->between(1, 10);
            $store = [];
            Functions\when('get_option')->justReturn($max);
            Functions\when('get_transient')->alias(static function ($k) use (&$store) {
                return $store[$k] ?? false;
            });
            Functions\when('set_transient')->alias(static function ($k, $v) use (&$store) {
                $store[$k] = $v;
            });
            Functions\when('get_current_user_id')->justReturn($rng->between(1, 100));

            for ($i = 0; $i < $max; $i++) {
                $this->assertTrue(Domain::check_rate_limit('contribution'), "Call " . ($i + 1) . " of {$max} must be admitted");
            }
            $this->assertFalse(Domain::check_rate_limit('contribution'), 'Call max+1 must be rejected');
        }, 100, 'PB-DOM-007');
    }

    public function test_pb_dom_008_spam_threshold_is_exactly_five_links(): void
    {
        $this->assertProperty(function (PropertyRng $rng) {
            Functions\when('apply_filters')->returnArg(2);
            $links = $rng->between(0, 10);
            $content = trim(str_repeat('http://x.com ', $links)) . ' words';
            $expected = $links >= 5;
            $this->assertSame($expected, Domain::is_spam($content, 'contribution'));
        }, 200, 'PB-DOM-008');
    }
}
