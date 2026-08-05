<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

class RelationUser extends User
{
    protected $table = 'relation_users';

    public function posts()
    {
        return $this->hasMany(User::class, 'user_id', 'id');
    }

    public function scalarRelation()
    {
        return 'not a relation query';
    }

    public function plainQuery()
    {
        return $this->newQuery();
    }
}
