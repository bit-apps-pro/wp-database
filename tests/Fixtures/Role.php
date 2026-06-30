<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

class Role extends Model
{
    public $timestamps = false;

    protected $table = 'roles';

    protected $primaryKey = 'id';
}
