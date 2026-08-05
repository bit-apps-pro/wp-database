<?php

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('wp_timezone_string')) {
    function wp_timezone_string()
    {
        return 'UTC';
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return $default;
    }
}

class FakeWpdb
{
    public $prefix = 'wp_';

    public $last_query = '';

    public $last_error = '';

    public $last_result = [];

    public $rows_affected = 0;

    public $insert_id = 0;

    public $suppress_errors = false;

    public $prepareCalls = 0;

    public $prepareFailure = false;

    public $queries = [];

    public function query($sql)
    {
        $this->last_query = $sql;
        $this->queries[]  = $sql;

        return $this->rows_affected;
    }

    public function prepare($query, ...$args)
    {
        $this->prepareCalls++;
        if ($this->prepareFailure) {
            return false;
        }

        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $index    = 0;
        $prepared = preg_replace_callback('/%[dsfF]/', function ($match) use (&$index, $args) {
            $value = isset($args[$index]) ? $args[$index] : '';
            $index++;

            return is_numeric($value) ? (string) $value : "'" . $value . "'";
        }, $query);

        return str_replace('%%', '%', $prepared);
    }

    public function get_results($query)
    {
        return $this->last_result;
    }
}

$GLOBALS['wpdb'] = new FakeWpdb();

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Fixtures/User.php';
require __DIR__ . '/Fixtures/RelationUser.php';
