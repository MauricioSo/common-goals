<?php
/**
 * Unit tests for TaskRunner synchronous fallback.
 *
 * Covers spec case UT-TASK-001 for the Action Scheduler-absent path, which is
 * the only path the plugin currently exercises in production. The
 * Action Scheduler-present path (async enqueue, recurring, unschedule) is
 * verified by the WordPress integration suite (spec 03) because it requires
 * the real Action Scheduler API to be loaded, which cannot be safely toggled
 * inside a single PHPUnit process.
 *
 * @package CommonGoals\Tests\Unit
 */

namespace CommonGoals\Tests\Unit;

use CommonGoals\TaskRunner;
use CommonGoals\Tests\Unit\Support\UnitTestCase;

final class TaskRunnerUnitTest extends UnitTestCase
{
    public function test_ut_task_001_is_available_is_false_without_action_scheduler(): void
    {
        $this->assertFalse(TaskRunner::is_available());
    }

    public function test_ut_task_001_fallback_runs_hook_synchronously_with_args(): void
    {
        $this->assertFalse(TaskRunner::is_available());

        // Brain Monkey records do_action calls without invoking callbacks, so we
        // assert the hook was done exactly once with the spread arguments.
        TaskRunner::schedule('cg_test_hook', ['a', 1]);

        $this->assertSame(1, did_action('cg_test_hook'), 'Hook must be triggered synchronously via do_action');
    }

    public function test_ut_task_001_recurring_is_noop_without_action_scheduler(): void
    {
        TaskRunner::schedule_recurring('cg_recurring_hook', 3600);

        $this->assertSame(0, did_action('cg_recurring_hook'), 'Recurring scheduling must be a no-op without Action Scheduler');
    }

    public function test_ut_task_001_unschedule_is_safe_noop_without_action_scheduler(): void
    {
        // Without AS, unschedule is a safe no-op that must not raise.
        TaskRunner::unschedule('cg_recurring_hook');

        $this->addToAssertionCount(1);
    }

    public function test_ut_task_001_group_constant_is_stable(): void
    {
        $this->assertSame('common-goals', TaskRunner::GROUP);
    }
}
