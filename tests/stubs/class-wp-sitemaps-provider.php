<?php
/**
 * Stub for WP_Sitemaps_Provider for unit testing.
 */

if (! class_exists('WP_Sitemaps_Provider')) {
    abstract class WP_Sitemaps_Provider
    {
        abstract public function get_url_list($page_num, $object_subtype = '');
        abstract public function get_max_num_pages($object_subtype = '');
    }
}
