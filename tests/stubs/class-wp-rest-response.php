<?php
/**
 * Stub for WP_REST_Response used by the unit test suite.
 *
 * @package CommonGoals
 */

if (! class_exists('WP_REST_Response')) {
    #[\AllowDynamicProperties]
    class WP_REST_Response
    {
        /** @var mixed */
        public $data;

        /** @var int */
        public $status;

        /** @var array<string, string> */
        public $headers = [];

        /**
         * @param mixed $data
         * @param int   $status
         */
        public function __construct($data = null, $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        public function header($key, $value): void
        {
            $this->headers[$key] = $value;
        }

        public function get_data()
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}
