<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

/**
 * Counts `saved` events so tests can assert the event fires on every successful
 * write path (0-row UPDATE, manual-PK INSERT) and never on a failed one.
 */
class SavedEventUser extends Model
{
    public $timestamps = false;

    public static $savedCount = 0;

    public static $createdCount = 0;

    public static $updatedCount = 0;

    protected $table = 'saved_event_users';

    protected $primaryKey = 'id';

    protected $fillable = ['name'];

    public static function resetCounters()
    {
        static::$savedCount   = 0;
        static::$createdCount = 0;
        static::$updatedCount = 0;
    }

    protected static function boot()
    {
        static::saved(function ($model) {
            static::$savedCount++;
        });
        static::created(function ($model) {
            static::$createdCount++;
        });
        static::updated(function ($model) {
            static::$updatedCount++;
        });
    }
}
