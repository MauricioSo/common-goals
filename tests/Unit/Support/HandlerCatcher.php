<?php
/**
 * Captures wp_safe_redirect and wp_die calls so admin-post handlers that end
 * with exit() or wp_die() stay testable inside a single PHPUnit process.
 *
 * Both wp_safe_redirect and wp_die are stubbed to throw dedicated exceptions,
 * which prevents fall-through to the real `exit` language construct and lets
 * the test assert on the redirect URL, notice code or the wp_die message.
 *
 * @package CommonGoals\Tests\Unit\Support
 */

namespace CommonGoals\Tests\Unit\Support;

use Brain\Monkey\Functions;

/**
 * Thrown by the stubbed wp_safe_redirect to short-circuit handler execution.
 */
final class RedirectException extends \RuntimeException
{
    public string $url;

    public function __construct(string $url)
    {
        parent::__construct('Redirect: ' . $url);
        $this->url = $url;
    }
}

/**
 * Thrown by the stubbed wp_die to short-circuit handler execution.
 */
final class WpDieException extends \RuntimeException
{
    public string $message_text;

    public function __construct(string $message)
    {
        parent::__construct('wp_die: ' . $message);
        $this->message_text = $message;
    }
}

/**
 * Installs wp_safe_redirect and wp_die stubs that throw catchable exceptions.
 */
trait HandlerCatcher
{
    protected function installHandlerCatcher(): void
    {
        Functions\when('wp_safe_redirect')->alias(static fn($url) => throw new RedirectException($url));
        Functions\when('wp_die')->alias(static fn($message = '') => throw new WpDieException((string) $message));
    }

    /**
     * Calls a callable, expecting a redirect to the given notice code.
     */
    protected function expectRedirectNotice(string $notice, callable $callback): void
    {
        $this->installHandlerCatcher();

        try {
            $callback();
            $this->fail('Expected a wp_safe_redirect, none was issued.');
        } catch (RedirectException $e) {
            $this->assertStringContainsString(
                'common_goals_notice=' . $notice,
                $e->url,
                "Expected redirect notice '{$notice}' but URL was: {$e->url}"
            );
        }
    }

    /**
     * Calls a callable, expecting wp_die to be invoked.
     */
    protected function expectWpDie(callable $callback): void
    {
        $this->installHandlerCatcher();

        try {
            $callback();
            $this->fail('Expected wp_die, none was issued.');
        } catch (WpDieException $e) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * Calls a callable, expecting a redirect (any notice) and returning it.
     */
    protected function captureRedirect(callable $callback): RedirectException
    {
        $this->installHandlerCatcher();

        try {
            $callback();
        } catch (RedirectException $e) {
            return $e;
        }
        $this->fail('Expected a wp_safe_redirect, none was issued.');
    }
}
