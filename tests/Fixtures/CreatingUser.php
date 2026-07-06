<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

class CreatingUser extends Model
{
    public $timestamps = false;

    public static $creatingCalled = false;

    public static $createdCalled = false;

    public static $abortCreating = false;

    protected $table = 'creating_users';

    protected $primaryKey = 'id';

    protected $fillable = ['name'];

    protected static function boot()
    {
        static::creating(function ($model) {
            static::$creatingCalled = true;

            if (static::$abortCreating) {
                return false;
            }
        });

        static::created(function ($model) {
            static::$createdCalled = true;
        });
    }
}
