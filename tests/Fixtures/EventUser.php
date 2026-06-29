<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

/**
 * Registers a `saving` handler that aborts the write by returning false.
 * Used to prove the documented "returning false from saving aborts" contract.
 */
class EventUser extends Model
{
    public $timestamps = false;

    public static $savingCalled = false;

    protected $table = 'event_users';

    protected $primaryKey = 'id';

    protected $fillable = ['name'];

    protected static function boot()
    {
        static::saving(function ($model) {
            static::$savingCalled = true;

            return false;
        });
    }
}
