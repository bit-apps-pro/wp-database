<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

class SoftPost extends Model
{
    public $timestamps = false;

    public $soft_deletes = true;

    protected $table = 'soft_posts';

    protected $primaryKey = 'id';
}
