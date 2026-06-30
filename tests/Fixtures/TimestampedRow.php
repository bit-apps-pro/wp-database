<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

/**
 * A model with timestamps enabled (the base Model default) for exercising the
 * upsert timestamp handling.
 */
class TimestampedRow extends Model
{
    public $timestamps = true;

    protected $table = 'timestamped_rows';

    protected $primaryKey = 'id';
}
