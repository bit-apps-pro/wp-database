<?php

/**
 * Class For Database Relations.
 */

namespace BitApps\WPDatabase;

use Closure;

if (!\defined('ABSPATH')) {
    exit;
}

trait HasEvents
{
    protected static $events = [];

    protected static function registerEvent($event, Closure $callback)
    {
        static::$events[$event] = $callback;
    }

    protected function fireEvent($event, $model = null)
    {
        if (\is_null($model)) {
            $model = $this;
        }

        if (isset(static::$events[$event]) && \is_callable(static::$events[$event])) {
            static::$events[$event]($model);
        }
    }

    protected static function retrieved(Closure $callback)
    {
        static::registerEvent('retrieved', $callback);
    }

    protected static function saving(Closure $callback)
    {
        static::registerEvent('saving', $callback);
    }

    protected static function saved(Closure $callback)
    {
        static::registerEvent('saved', $callback);
    }

    protected static function updating(Closure $callback)
    {
        static::registerEvent('updating', $callback);
    }

    protected static function updated(Closure $callback)
    {
        static::registerEvent('updated', $callback);
    }

    protected static function deleting(Closure $callback)
    {
        static::registerEvent('deleting', $callback);
    }

    protected static function deleted(Closure $callback)
    {
        static::registerEvent('deleted', $callback);
    }
}
