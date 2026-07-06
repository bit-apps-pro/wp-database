<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

use BitApps\WPDatabase\Model;

/**
 * Model with a custom (plugin) $prefix, like bit-crm: the wp_ prefix is added
 * on top of $prefix, so the real table is wp_<prefix><table>.
 */
class PrefixedModel extends Model
{
    public $timestamps = false;

    protected $table = 'widgets';

    protected $prefix = 'crm_';
}
