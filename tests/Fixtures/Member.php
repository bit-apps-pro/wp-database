<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

class Member extends Model
{
    public $timestamps = false;

    protected $table = 'members';

    protected $primaryKey = 'id';

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user', 'member_id', 'role_id');
    }

    public function rolesDefaultKeys()
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function rolesWithPivot()
    {
        return $this->belongsToMany(Role::class, 'role_user', 'member_id', 'role_id')->withPivot(['assigned_at']);
    }

    public function legacyRoles()
    {
        return $this->belongsToMany(Role::class);
    }
}
