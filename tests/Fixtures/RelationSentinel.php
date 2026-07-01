<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

/**
 * Fixture for relation-resolution safety: one real relation plus a NON-relation
 * public method whose return is not a relation query, to prove
 * with()/withCount()/whereHas() reject arbitrary methods by name.
 */
class RelationSentinel extends Model
{
    public $timestamps = false;

    protected $table = 'sentinels';

    public function posts()
    {
        return $this->hasMany(Post::class, 'sentinel_id', 'id');
    }

    /** NOT a relation — must be rejected, never used as one. */
    public function destroyTheWorld()
    {
        return 'not a query builder';
    }
}
