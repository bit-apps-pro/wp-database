<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

/**
 * Captures the model passed to the `retrieved` event so a test can assert the
 * model is already hydrated when the event fires.
 */
class RetrieveUser extends Model
{
    public $timestamps = false;

    public static $seenId = 'UNSET';

    protected $table = 'retrieve_users';

    protected $primaryKey = 'id';

    protected static function boot()
    {
        static::retrieved(function ($model) {
            static::$seenId = $model->id;
        });
    }
}
