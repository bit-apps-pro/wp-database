<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

class AccessorModel extends Model
{
    public $timestamps = false;

    protected $table = 'accessor_models';

    protected $primaryKey = 'id';

    public function getLabelAttribute()
    {
        return 'L';
    }
}
