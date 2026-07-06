<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

class ScopedSoftPost extends Model
{
    public $timestamps = false;

    public $soft_deletes = true;

    public $soft_delete_scope = true;

    protected $table = 'scoped_soft_posts';

    protected $primaryKey = 'id';
}
