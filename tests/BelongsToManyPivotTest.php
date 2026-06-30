<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Collection;
use BitApps\WPDatabase\Model;
use BitApps\WPDatabase\QueryBuilder;
use BitApps\WPDatabase\Tests\Fixtures\Member;
use BitApps\WPDatabase\Tests\Fixtures\Role;
use FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Read-side pivot-table many-to-many on belongsToMany: eager with(), lazy
 * access, withPivot, default-key derivation, the legacy null-pivot BC path and
 * the out-of-scope aggregate guard.
 */
final class BelongsToManyPivotTest extends TestCase
{
    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    /** Resolves the related/pivot query first; the related query also names wp_members in its subquery. */
    private function pivotResolver(): callable
    {
        return function ($sql) {
            if (strpos($sql, 'wp_roles') !== false) {
                return [
                    (object) ['id' => 100, 'pivot_member_id' => 1, 'pivot_assigned_at' => '2024-01-01'],
                    (object) ['id' => 101, 'pivot_member_id' => 1, 'pivot_assigned_at' => '2024-02-01'],
                    (object) ['id' => 102, 'pivot_member_id' => 2, 'pivot_assigned_at' => '2024-03-01'],
                ];
            }

            return [(object) ['id' => 1], (object) ['id' => 2]];
        };
    }

    public function testEagerLoadGroupsRelatedRowsByParent(): void
    {
        $GLOBALS['wpdb']->resolver = $this->pivotResolver();

        $members = Member::with('roles')->get();

        $this->assertInstanceOf(Collection::class, $members);
        $this->assertCount(2, $members);
        $this->assertCount(2, $members[0]->roles, 'member 1 should have 2 roles');
        $this->assertCount(1, $members[1]->roles, 'member 2 should have 1 role');
        $this->assertInstanceOf(Role::class, $members[0]->roles[0]);
        $this->assertSame(100, $members[0]->roles[0]->id);
        $this->assertSame(101, $members[0]->roles[1]->id);
        $this->assertSame(102, $members[1]->roles[0]->id);
    }

    public function testEagerSqlShape(): void
    {
        $GLOBALS['wpdb']->resolver = $this->pivotResolver();

        Member::with('roles')->get();
        $sql = $GLOBALS['wpdb']->queries[1];

        $this->assertStringContainsString('SELECT `wp_roles`.*', $sql);
        $this->assertStringContainsString('wp_role_user.member_id as `pivot_member_id`', $sql);
        $this->assertStringContainsString('INNER JOIN wp_role_user', $sql);
        $this->assertStringContainsString('wp_role_user.role_id = wp_roles.id', $sql);
        // Inner fragment without a leading `WHERE ` boundary: the grammar emits `WHERE  ` (double space).
        $this->assertStringContainsString(
            'wp_role_user.member_id IN ( SELECT * FROM (SELECT `wp_members`.`id` FROM wp_members) AS subquery )',
            $sql
        );

        // Exact pin (absorbs the double-space WHERE/ON grammar artifacts).
        $expected = 'SELECT `wp_roles`.*, wp_role_user.member_id as `pivot_member_id`'
            . ' FROM wp_roles INNER JOIN wp_role_user ON  wp_role_user.role_id = wp_roles.id'
            . ' WHERE  wp_role_user.member_id IN ( SELECT * FROM (SELECT `wp_members`.`id` FROM wp_members) AS subquery )';
        $this->assertSame($expected, $sql);
    }

    public function testLazyAccessEmitsSingleValuePredicate(): void
    {
        $GLOBALS['wpdb']->resolver = $this->pivotResolver();

        $member = new Member(['id' => 1]);
        $roles  = $member->roles;

        $this->assertInstanceOf(Collection::class, $roles);

        $sql = $GLOBALS['wpdb']->last_query;
        $this->assertStringNotContainsString('subquery', $sql, 'lazy access must not use the IN ( SELECT ) subquery form');

        // Exact pin: single-value predicate, double-space WHERE/ON/`=` grammar artifacts.
        $expected = 'SELECT `wp_roles`.*, wp_role_user.member_id as `pivot_member_id`'
            . ' FROM wp_roles INNER JOIN wp_role_user ON  wp_role_user.role_id = wp_roles.id'
            . ' WHERE  wp_role_user.member_id =  1';
        $this->assertSame($expected, $sql);
    }

    public function testDefaultKeyDerivationUsesForeignKeyConvention(): void
    {
        $GLOBALS['wpdb']->resolver = function ($sql) {
            if (strpos($sql, 'wp_roles') !== false) {
                return [
                    (object) ['id' => 100, 'pivot_members_id' => 1],
                    (object) ['id' => 101, 'pivot_members_id' => 1],
                    (object) ['id' => 102, 'pivot_members_id' => 2],
                ];
            }

            return [(object) ['id' => 1], (object) ['id' => 2]];
        };

        $members = Member::with('rolesDefaultKeys')->get();
        $sql     = $GLOBALS['wpdb']->queries[1];

        $this->assertStringContainsString('wp_role_user.members_id as `pivot_members_id`', $sql);
        $this->assertStringContainsString('wp_role_user.roles_id = wp_roles.id', $sql);
        $this->assertCount(2, $members[0]->rolesDefaultKeys);
        $this->assertCount(1, $members[1]->rolesDefaultKeys);
    }

    public function testWithPivotSelectsAndExposesExtraColumn(): void
    {
        $GLOBALS['wpdb']->resolver = $this->pivotResolver();

        $members = Member::with('rolesWithPivot')->get();
        $sql     = $GLOBALS['wpdb']->queries[1];

        $this->assertStringContainsString('wp_role_user.assigned_at as `pivot_assigned_at`', $sql);
        $this->assertSame('2024-01-01', $members[0]->rolesWithPivot[0]->pivot_assigned_at);
    }

    public function testLegacyNullPivotPathIsUnchanged(): void
    {
        $query = (new Member())->legacyRoles();

        $this->assertInstanceOf(QueryBuilder::class, $query);
        $this->assertSame('belongsToMany', $query->getModel()->getRelateAs());
        $this->assertSame(
            ['foreignKey' => 'members_id', 'localKey' => 'id'],
            $query->getModel()->getActiveRelationKey()
        );
    }

    public function testAggregatesOnPivotRelationThrow(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pivot belongsToMany');

        Member::withCount('roles')->get();
    }

    public function testBucketAliasLeaksAsReservedAttribute(): void
    {
        $GLOBALS['wpdb']->resolver = $this->pivotResolver();

        $members = Member::with('roles')->get();

        $this->assertSame(1, $members[0]->roles[0]->pivot_member_id);
        $this->assertSame(2, $members[1]->roles[0]->pivot_member_id);
    }

    public function testMultiplePivotRelationsResolveIndependently(): void
    {
        $GLOBALS['wpdb']->resolver = function ($sql) {
            if (strpos($sql, 'assigned_at') !== false) {
                return [
                    (object) ['id' => 200, 'pivot_member_id' => 1, 'pivot_assigned_at' => '2024-01-01'],
                    (object) ['id' => 201, 'pivot_member_id' => 2, 'pivot_assigned_at' => '2024-02-01'],
                ];
            }

            if (strpos($sql, 'wp_roles') !== false) {
                return [
                    (object) ['id' => 100, 'pivot_member_id' => 1],
                    (object) ['id' => 102, 'pivot_member_id' => 2],
                ];
            }

            return [(object) ['id' => 1], (object) ['id' => 2]];
        };

        $members = Member::with(['roles', 'rolesWithPivot'])->get();

        $this->assertCount(1, $members[0]->roles);
        $this->assertCount(1, $members[0]->rolesWithPivot);
        $this->assertSame(100, $members[0]->roles[0]->id);
        $this->assertSame(200, $members[0]->rolesWithPivot[0]->id);
        $this->assertSame('2024-01-01', $members[0]->rolesWithPivot[0]->pivot_assigned_at);
        $this->assertNull($members[0]->roles[0]->pivot_assigned_at, 'roles must not pick up the withPivot column');
    }

    public function testPivotConstantsAreExposedOnModel(): void
    {
        $this->assertSame('belongsToManyPivot', Model::RELATE_AS_PIVOT);
        $this->assertSame('pivot_', Model::PIVOT_ATTRIBUTE_PREFIX);
    }
}
