<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

/**
 * An intermediate base (between a leaf model and the framework Model) that
 * declares a relation, to prove the relation guard allows a relation defined on
 * a base class — its declaring class is this base, not the framework Model.
 */
class RelationBaseModel extends Model
{
    public $timestamps = false;

    protected $table = 'relation_bases';

    public function widgets()
    {
        return $this->hasMany(Post::class, 'base_id', 'id');
    }
}
