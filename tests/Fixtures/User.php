<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

class User extends Model
{
    public $timestamps = false;

    protected $table = 'users';

    protected $primaryKey = 'id';

    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id', 'id');
    }
}
