<?php

namespace BitApps\WPDatabase\Tests;

use BitApps\WPDatabase\Model;
use BitApps\WPDatabase\Tests\Fixtures\Member;
use BitApps\WPDatabase\Tests\Fixtures\User;
use FakeWpdb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RelationshipIdentifierSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        SafetyRelationParent::$foreignKey = 'parent_id';
        SafetyRelationParent::$localKey   = 'id';
        SafetyPivotParent::$pivotTable      = 'role_user';
        SafetyPivotParent::$foreignPivotKey = 'member_id';
        SafetyPivotParent::$relatedPivotKey = 'role_id';
        SafetyPivotParent::$parentKey       = 'id';
        SafetyPivotParent::$relatedKey      = 'id';
        SafetyPivotParent::$pivotColumns    = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public static function hostileRelationAliases(): array
    {
        return [
            'backtick'          => ['posts as total`'],
            'comment'           => ['posts as total --'],
            'expression'        => ['posts as COUNT(id)'],
            'qualified segment' => ['posts as report.total'],
        ];
    }

    #[DataProvider('hostileRelationAliases')]
    public function testAggregateRelationAliasRejectsHostileIdentifierBeforeExecution(string $relation): void
    {
        $this->assertRejectedBeforeExecution(static function () use ($relation): void {
            User::withCount($relation)->get();
        });
    }

    #[DataProvider('hostileRelationAliases')]
    public function testWhereHasRelationAliasRejectsHostileIdentifierBeforeExecution(string $relation): void
    {
        $this->assertRejectedBeforeExecution(static function () use ($relation): void {
            User::whereHas($relation)->get();
        });
    }

    public static function hostileHasManyKeys(): array
    {
        return [
            'foreign key backtick' => ['foreignKey', 'parent_id` OR 1=1 --'],
            'foreign key comment'  => ['foreignKey', 'parent_id/*x*/'],
            'local key expression' => ['localKey', 'COALESCE(id, 0)'],
            'local key dot'        => ['localKey', 'parents.id'],
        ];
    }

    #[DataProvider('hostileHasManyKeys')]
    public function testHasManyKeyRejectsNonSimpleSegmentBeforeExecution(string $property, string $value): void
    {
        SafetyRelationParent::${$property} = $value;

        $this->assertRejectedBeforeExecution(static function (): void {
            SafetyRelationParent::with('unsafeChildren')->get();
        });
    }

    public static function hostilePivotMetadata(): array
    {
        return [
            'pivot table comment'        => ['pivotTable', 'role_user/*x*/'],
            'pivot table alias'          => ['pivotTable', 'role_user AS pivot_link'],
            'foreign pivot key backtick' => ['foreignPivotKey', 'member_id`'],
            'foreign pivot key dot'      => ['foreignPivotKey', 'pivot.member_id'],
            'related pivot key comment'  => ['relatedPivotKey', 'role_id--'],
            'parent key expression'      => ['parentKey', 'COALESCE(id, 0)'],
            'related key dot'            => ['relatedKey', 'roles.id'],
        ];
    }

    #[DataProvider('hostilePivotMetadata')]
    public function testPivotMetadataRejectsNonSimpleSegmentBeforeExecution(string $property, string $value): void
    {
        SafetyPivotParent::${$property} = $value;

        $this->assertRejectedBeforeExecution(static function (): void {
            SafetyPivotParent::with('unsafePivots')->get();
        });
    }

    public static function hostilePivotColumns(): array
    {
        return [
            'backtick'          => ['assigned_at`'],
            'comment'           => ['assigned_at/*x*/'],
            'expression'        => ['MAX(assigned_at)'],
            'qualified segment' => ['role_user.assigned_at'],
        ];
    }

    #[DataProvider('hostilePivotColumns')]
    public function testPivotSelectedColumnAndDerivedAliasRejectHostileIdentifierBeforeExecution(string $column): void
    {
        SafetyPivotParent::$pivotColumns = [$column];

        $this->assertRejectedBeforeExecution(static function (): void {
            SafetyPivotParent::with('unsafePivots')->get();
        });
    }

    public function testAggregateColumnRejectsRawExpressionBeforeExecution(): void
    {
        $this->assertRejectedBeforeExecution(static function (): void {
            User::withAggregate('posts', 'amount) AS injected --', 'sum')->get();
        });
    }

    public function testValidAggregateAliasIsQuotedAndUsesStructuredSubquery(): void
    {
        $query = User::withCount('posts as published_total');
        $sql   = $query->toSql();

        $this->assertStringContainsStringIgnoringCase('AS `published_total`', $sql);
        $this->assertSame([], $query->selectRaw['columns']);
    }

    public function testValidPivotIdentifiersKeepPrefixAndQuoteDerivedAliases(): void
    {
        $GLOBALS['wpdb']->resolver = static function ($sql) {
            if (strpos($sql, 'wp_roles') !== false) {
                return [(object) ['id' => 10, 'pivot_member_id' => 1, 'pivot_assigned_at' => '2024-01-01']];
            }

            return [(object) ['id' => 1]];
        };

        Member::with('rolesWithPivot')->get();
        $sql = $GLOBALS['wpdb']->queries[1];

        $this->assertStringContainsString('INNER JOIN `wp_role_user`', $sql);
        $this->assertStringContainsString('`wp_role_user`.`member_id` AS `pivot_member_id`', $sql);
        $this->assertStringContainsString('`wp_role_user`.`assigned_at` AS `pivot_assigned_at`', $sql);
    }

    private function assertRejectedBeforeExecution(callable $operation): void
    {
        $exception = null;

        try {
            $operation();
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }
}

final class SafetyRelationParent extends Model
{
    public static $foreignKey = 'parent_id';

    public static $localKey = 'id';

    public $timestamps = false;

    protected $table = 'safety_parents';

    public function unsafeChildren()
    {
        return $this->hasMany(SafetyRelationChild::class, self::$foreignKey, self::$localKey);
    }
}

final class SafetyRelationChild extends Model
{
    public $timestamps = false;

    protected $table = 'safety_children';
}

final class SafetyPivotParent extends Model
{
    public static $pivotTable = 'role_user';

    public static $foreignPivotKey = 'member_id';

    public static $relatedPivotKey = 'role_id';

    public static $parentKey = 'id';

    public static $relatedKey = 'id';

    public static $pivotColumns = [];

    public $timestamps = false;

    protected $table = 'safety_pivot_parents';

    public function unsafePivots()
    {
        return $this->belongsToMany(
            SafetyPivotRelated::class,
            self::$pivotTable,
            self::$foreignPivotKey,
            self::$relatedPivotKey,
            self::$parentKey,
            self::$relatedKey
        )->withPivot(self::$pivotColumns);
    }
}

final class SafetyPivotRelated extends Model
{
    public $timestamps = false;

    protected $table = 'safety_pivot_related';
}
