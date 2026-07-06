<?php

namespace BitApps\WPDatabase;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use ReturnTypeWillChange;

class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    protected $items = [];

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public static function make($items = [])
    {
        return new static(\is_array($items) ? $items : [$items]);
    }

    public function all()
    {
        return $this->items;
    }

    public function map(callable $callback)
    {
        return new static(array_map($callback, $this->items));
    }

    public function filter(callable $callback)
    {
        return new static(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    public function reduce(callable $callback, $initial = null)
    {
        return array_reduce($this->items, $callback, $initial);
    }

    public function pluck($key)
    {
        return new static(array_map(function ($item) use ($key) {
            if (\is_array($item)) {
                return $item[$key] ?? null;
            }

            if ($item instanceof Model) {
                return $item->getAttribute($key);
            }

            return \is_object($item) ? $item->{$key} ?? null : null;
        }, $this->items));
    }

    public function first(?callable $callback = null, $default = null)
    {
        foreach ($this->items as $item) {
            if ($callback === null || $callback($item)) {
                return $item;
            }
        }

        return $default;
    }

    public function last(?callable $callback = null, $default = null)
    {
        return $this->reverse()->first($callback, $default);
    }

    public function reverse()
    {
        return new static(array_reverse($this->items));
    }

    public function toArray()
    {
        return array_map(function ($value) {
            return \is_object($value) && method_exists($value, 'toArray') ? $value->toArray() : $value;
        }, $this->items);
    }

    public function jsonSerialize():array
    {
        return $this->toArray();
    }

    #[ReturnTypeWillChange]
    public function count()
    {
        return \count($this->items);
    }

    #[ReturnTypeWillChange]
    public function getIterator()
    {
        return new ArrayIterator($this->items);
    }

    #[ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return isset($this->items[$offset]);
    }

    #[ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->items[$offset] ?? null;
    }

    #[ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    #[ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->items[$offset]);
    }
}
