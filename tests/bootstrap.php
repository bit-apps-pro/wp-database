<?php

/**
 * PHPUnit bootstrap.
 *
 * Provides the minimal WordPress runtime the package touches: the `ABSPATH`
 * guard constant, a handful of WP helper functions, and a fake `$wpdb` global
 * so `Connection` can resolve a prefix and capture executed SQL without a real
 * database.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return $text;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return $default;
    }
}

if (!function_exists('wp_timezone_string')) {
    function wp_timezone_string()
    {
        return 'UTC';
    }
}

/**
 * Stand-in for the global `$wpdb` object. Records the last executed query and
 * returns whatever result set the test queued via {@see FakeWpdb::queueResult()}.
 */
class FakeWpdb
{
    public $prefix = 'wp_';

    public $last_query = '';

    public $last_error = '';

    public $last_result = [];

    public $rows_affected = 0;

    public $insert_id = 0;

    public $suppress_errors = false;

    /**
     * @var string[]
     */
    public $queries = [];

    /**
     * @var null|callable resolves a result set from the SQL string
     */
    public $resolver;

    public function queueResult(array $rows)
    {
        $this->last_result = $rows;
    }

    public function query($sql)
    {
        $this->last_query = $sql;
        $this->queries[]  = $sql;

        if (is_callable($this->resolver)) {
            $this->last_result = ($this->resolver)($sql);
        }

        return $this->rows_affected;
    }

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $index = 0;

        return preg_replace_callback(
            '/%[dsfF]/',
            function ($match) use (&$index, $args) {
                $value = $args[$index] ?? '';
                $index++;

                return is_numeric($value) ? (string) $value : "'" . $value . "'";
            },
            $query
        );
    }

    public function get_results($query)
    {
        return $this->last_result;
    }

    public function has_cap($cap)
    {
        return false;
    }
}

$GLOBALS['wpdb'] = new FakeWpdb();

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Fixtures/Post.php';
require __DIR__ . '/Fixtures/User.php';
require __DIR__ . '/Fixtures/SoftPost.php';
require __DIR__ . '/Fixtures/EventUser.php';
require __DIR__ . '/Fixtures/RetrieveUser.php';
require __DIR__ . '/Fixtures/CastModel.php';
require __DIR__ . '/Fixtures/AccessorModel.php';
require __DIR__ . '/Fixtures/CreatingUser.php';
require __DIR__ . '/Fixtures/ScopedSoftPost.php';
require __DIR__ . '/Fixtures/TimestampedRow.php';
require __DIR__ . '/Fixtures/Role.php';
require __DIR__ . '/Fixtures/Member.php';
require __DIR__ . '/Fixtures/PrefixedModel.php';
