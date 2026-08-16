<?php
/**
 * Minimal WP_REST_Request double for unit testing REST callbacks.
 *
 * @package CommonGoals\Tests\Unit\Support
 */

namespace CommonGoals\Tests\Unit\Support;

/**
 * Supports both array-access route params and get_param() lookups. Extends the
 * WP_REST_Request stub so it satisfies REST callback type hints.
 */
final class RequestStub extends \WP_REST_Request
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(array $params = [])
    {
        parent::__construct($params);
    }
}
