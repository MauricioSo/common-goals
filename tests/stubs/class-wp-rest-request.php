<?php
/**
 * Stub for WP_REST_Request used by the unit test suite.
 *
 * @package CommonGoals
 */

if (! class_exists('WP_REST_Request')) {
    #[\AllowDynamicProperties]
    class WP_REST_Request implements \ArrayAccess
    {
        /** @var array<string, mixed> */
        private $params = [];

        /**
         * @param array<string, mixed> $params
         */
        public function __construct(array $params = [])
        {
            $this->params = $params;
        }

        /**
         * @param string $key
         * @return mixed|null
         */
        public function get_param($key)
        {
            return $this->params[$key] ?? null;
        }

        public function offsetExists($offset): bool
        {
            return isset($this->params[$offset]);
        }

        public function offsetGet($offset): mixed
        {
            return $this->params[$offset] ?? null;
        }

        public function offsetSet($offset, $value): void
        {
            $this->params[$offset] = $value;
        }

        public function offsetUnset($offset): void
        {
            unset($this->params[$offset]);
        }
    }
}
