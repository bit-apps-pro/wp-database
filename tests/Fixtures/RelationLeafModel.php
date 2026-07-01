<?php

namespace BitApps\WPDatabase\Tests\Fixtures;

/**
 * Leaf model whose relation (widgets) is declared on its intermediate base.
 */
class RelationLeafModel extends RelationBaseModel
{
    protected $table = 'relation_leaves';
}
