<?php

namespace BitApps\WPDatabase;

use Closure;

use ErrorException;

use RuntimeException;

class Schema
{
    public $prefix;

    public static function __callStatic($method, $parameters)
    {
        return (new self())->{$method}(...$parameters);
    }

    public function __call($method, $parameters)
    {
        if ($method === 'withPrefix') {
            $this->prefix = $parameters[0];

            return $this;
        }

        if (!method_exists(Blueprint::class, $method)) {
            throw new RuntimeException('Undefined method [' . $method . '] called on Schema class.');
        }

        if (\is_null($parameters)) {
            throw new ErrorException('Expected at least 1 parameter, 0 given.');
        }

        if (\count($parameters) > 1 && $parameters[1] instanceof Closure) {
            $blueprint = $this->createBlueprint($parameters[0], $method, $parameters[1]);
            unset($parameters[0], $parameters[1]);
        } else {
            $blueprint = $this->createBlueprint($parameters[0], $method);
            unset($parameters[0]);
        }

        \call_user_func_array([$blueprint, $method], $parameters);

        return $this->build($blueprint);
    }

    public function createBlueprint($schema, $method, ?Closure $callback = null)
    {
        return new Blueprint(
            $schema,
            $method,
            $this->prefix === '' ? Connection::getPrefix() : $this->prefix,
            $callback
        );
    }

    public function build(Blueprint $blueprint)
    {
        return $blueprint->build();
    }
}
