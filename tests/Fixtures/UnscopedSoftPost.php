<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

class UnscopedSoftPost extends Model
{
    public $timestamps = false;

    public $soft_deletes = true;

    public $soft_delete_scope = false;

    protected $table = 'unscoped_soft_posts';

    protected $primaryKey = 'id';
}
