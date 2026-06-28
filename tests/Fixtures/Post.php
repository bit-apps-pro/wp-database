<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

class Post extends Model
{
    public $timestamps = false;

    protected $table = 'posts';

    protected $primaryKey = 'id';
}
