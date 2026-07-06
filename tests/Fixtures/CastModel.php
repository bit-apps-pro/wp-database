<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

class CastModel extends Model
{
    public $timestamps = false;

    protected $table = 'cast_models';

    protected $primaryKey = 'id';

    protected $casts = ['flag' => 'boolean'];
}
