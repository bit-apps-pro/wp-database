<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

class CastAliasModel extends Model
{
    public $timestamps = false;

    protected $table = 'cast_alias_models';

    protected $primaryKey = 'id';

    protected $casts = [
        'n'    => 'integer',
        'f'    => 'float',
        'd'    => 'double',
        'data' => 'json',
        'at'   => 'datetime',
    ];
}
