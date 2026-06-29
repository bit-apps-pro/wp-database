<?php

/**
 * Class For Database Relations.
 */

namespace BitApps\WPDatabase\Concerns;

use Closure;

if (!\defined('ABSPATH')) {
    exit;
}

trait HasEvents
{
    protected $events = [];

    protected static $registeredEvents = [];

    public function fireEvent($event, $model = null)
    {
        if (\is_null($model)) {
            $model = $this;
        }

        if (isset(static::$registeredEvents[static::class . $event]) && \is_callable(static::$registeredEvents[static::class . $event])) {
            return static::$registeredEvents[static::class . $event]($model);
        } elseif (isset($this->events[$event])) {
            return $this->fireCustomEvent($this->events[$event], $model);
        }
    }

    public function fireCustomEvent($callback, $model)
    {
        if ($callback instanceof Closure) {
            return $callback($model);
        } elseif (class_exists($callback) && method_exists($callback, 'handle')) {
            return (new $callback($model))->handle();
        }
    }

    protected static function registerEvent($event, Closure $callback)
    {
        static::$registeredEvents[static::class . $event] = $callback;
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
