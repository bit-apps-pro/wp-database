<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

if (!\defined('ABSPATH')) {
    exit;
}

class User extends Model
{
    public $timestamps = false;

    protected $table = 'users';

    protected $fillable = ['id', 'name', 'email'];
}
