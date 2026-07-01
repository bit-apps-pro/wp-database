# wp-database — Relationships

Full reference for defining and loading relationships. For the broader API see
the [Usage guide](usage.md).

Relationships are declared as **methods** on a model; each method returns a query
for the related model. Load them eagerly with `with()` or lazily by accessing the
method name as a property (`$model->relation`).

- [Key convention](#key-convention)
- [`hasOne` / `belongsTo` (one-to-one)](#hasone--belongsto-one-to-one)
- [`hasMany` (one-to-many)](#hasmany-one-to-many)
- [`belongsToMany` (many-to-many)](#belongstomany-many-to-many)
- [Eager loading](#eager-loading)
- [Lazy loading](#lazy-loading)
- [Relation aggregates & existence](#relation-aggregates--existence)
- [Limitations](#limitations)

---

## Key convention

> **`$foreignKey` is the column on the _related_ model's table; `$localKey` is
> the column on the _calling_ model's table.** This is the **reverse** of
> Laravel's naming — always pass both arguments explicitly when your columns
> differ from the ORM default (`{callerTable}_id` / `id`) to avoid confusion.

Every relation method takes the same first three arguments:

```php
relation($model, $foreignKey = null, $localKey = null)
```

Omitted keys derive from the package's foreign-key convention: `$foreignKey`
defaults to the caller's `getForeignKey()` (`{tableWithoutPrefix}_{primaryKey}`,
e.g. `contacts_id` — note: plural, unlike Laravel's singular default) and
`$localKey` defaults to the caller's primary key.

---

## `hasOne` / `belongsTo` (one-to-one)

**`hasOne()` is a direct alias of `belongsTo()`.** Both set the same `oneToOne`
relation type and return a single related model. There is no separate
reverse-direction implementation — direction is determined entirely by the keys
you pass and which model calls the method.

```php
class Contact extends Model
{
    public function profile()
    {
        // foreignKey='contact_id' on the profiles (related) table
        // localKey='id' on the contacts (this) table
        // Predicate: WHERE profiles.contact_id IN (SELECT id FROM contacts)
        return $this->hasOne(Profile::class, 'contact_id', 'id');
    }
}

class Deal extends Model
{
    public function contact()
    {
        // Deal.contact_id references Contact.id
        // foreignKey='id' on the contacts (related) table
        // localKey='contact_id' on the deals (this) table
        // Predicate: WHERE contacts.id IN (SELECT contact_id FROM deals)
        return $this->belongsTo(Contact::class, 'id', 'contact_id');
    }
}
```

A `oneToOne` relation resolves to a single `Model` (or `[]` when there is no
match), not a `Collection`.

---

## `hasMany` (one-to-many)

Same key convention; resolves to a `Collection` of related models.

```php
class Contact extends Model
{
    public function deals()
    {
        // foreignKey='contact_id' lives on the deals table
        // localKey='id' lives on the contacts table
        // Predicate: WHERE deals.contact_id IN (SELECT id FROM contacts)
        return $this->hasMany(Deal::class, 'contact_id', 'id');
    }
}
```

---

## `belongsToMany` (many-to-many)

Resolves a many-to-many relation through a pivot (junction) table. Signature:

```php
belongsToMany(
    $model,
    $pivotTable = null,      // unprefixed pivot/junction table name
    $foreignPivotKey = null, // parent's key column ON the pivot table
    $relatedPivotKey = null, // related's key column ON the pivot table
    $parentKey = null,       // local key column on the parent table
    $relatedKey = null       // key column on the related table
)
```

When `$pivotTable` is `null` the method keeps its **legacy** behaviour (resolves
exactly like `hasMany` — the related table must carry the parent FK). Pass the
pivot table name (unprefixed; the package prefixes it like `join()` does) to get
real pivot behaviour.

Omitted keys derive from the package's foreign-key convention:

| Argument | Default | Member (`members`) ↔ Role (`roles`) |
|---|---|---|
| `$foreignPivotKey` | parent `getForeignKey()` | `members_id` |
| `$relatedPivotKey` | related `getForeignKey()` | `roles_id` |
| `$parentKey` | parent `getPrimaryKey()` | `id` |
| `$relatedKey` | related `getPrimaryKey()` | `id` |

```php
class Member extends Model
{
    protected $table = 'members';

    public function roles()
    {
        // pivot table role_user(member_id, role_id)
        return $this->belongsToMany(Role::class, 'role_user', 'member_id', 'role_id');
    }

    // Carry extra pivot columns; they surface flat as `pivot_<col>` attributes
    public function rolesWithAssignment()
    {
        return $this->belongsToMany(Role::class, 'role_user', 'member_id', 'role_id')
            ->withPivot(['assigned_at']);
    }
}
```

The link column rides along on every related model as the reserved attribute
`pivot_<foreignPivotKey>` (e.g. `pivot_member_id`), and each `withPivot()` column
as `pivot_<column>` (e.g. `pivot_assigned_at`). These flat `pivot_*` attributes
appear in `toArray()`.

```php
// Eager
foreach (Member::with('roles')->get() as $member) {
    foreach ($member->roles as $role) {
        echo $role->pivot_member_id;          // the parent link
    }
}

// Lazy
$member = Member::query()->findOne(['id' => 1]);
foreach ($member->roles as $role) { /* ... */ }
```

Pivot relations are **read-only**: `attach`/`detach`/`sync` and
`withCount`/`whereHas`/aggregates over a pivot relation are **not** supported
(the aggregates throw a `RuntimeException`). See [Limitations](#limitations).

---

## Eager loading

Load a relation for every parent in one extra query (avoids the N+1 problem).

```php
Contact::with('deals')->get();                   // load deals for each contact
Contact::with(['deals', 'profile'])->get();      // multiple relations

// Constrain the eager-loaded relation
Contact::with('deals', function ($q) {
    $q->where('status', 'open');
})->get();

// Alias the loaded relation key on the result model
Contact::with('deals as open_deals')->get();

// Access loaded relations
foreach (Contact::with('deals')->get() as $contact) {
    foreach ($contact->deals as $deal) { /* ... */ }
}
```

A parent with no related rows resolves to an empty result **without** triggering
a fresh lazy query when the relation is later accessed — the eager load already
resolved it to empty.

---

## Lazy loading

Access the relation method name as a property to load it on demand:

```php
$contact = Contact::query()->findOne(['id' => 1]);

$contact->deals;    // Collection, queried on first access
$contact->profile;  // single Model or []
```

---

## Relation aggregates & existence

```php
Contact::withCount('deals')->get();           // adds `deals_count`
Contact::withSum('deals.amount')->get();      // adds `deals_sum`
Contact::withAvg('deals.amount')->get();      // adds `deals_avg`
Contact::withMin('deals.amount')->get();      // adds `deals_min`
Contact::withMax('deals.amount')->get();      // adds `deals_max`
Contact::withExists('deals')->get();          // adds bool-cast `deals_exists`

// Filter by relation existence
Contact::whereHas('deals')->get();
Contact::whereHas('deals', fn ($q) => $q->where('status', 'open'))->get();

// Filter by existence AND eager-load the same relation
Contact::withWhereHas('deals', fn ($q) => $q->where('status', 'open'))->get();
```

Aggregate columns are aliased `<relation>_<function>` by default
(e.g. `deals_count`, `deals_sum`). Pass `'relation as alias'` for a custom name:

```php
Contact::withCount('deals as total_deals')->get();   // adds `total_deals`
Contact::withSum('deals.amount as revenue')->get();  // adds `revenue`
```

For `withMin`, `withMax`, `withAvg`, and `withSum` the column to aggregate must
be given as `'relation.column'`; passing just the relation name defaults to `*`,
which is meaningful only for `withCount` and `withExists`.

---

## Limitations

- **`belongsTo` and `hasOne` are the same alias; key naming is reversed from
  Laravel.** Both set the `oneToOne` relation. `$foreignKey` is the column on the
  **related** table and `$localKey` is the column on the **calling** model's
  table — the opposite of Laravel. Supply both arguments explicitly.

- **`belongsToMany` pivot relations are read-only and single-key.** Real
  pivot-table many-to-many is supported for reads (eager `with()` + lazy
  `$model->relation`), with these gaps:
  - No `withCount`/`whereHas`/aggregates on a pivot relation — they **throw**
    `RuntimeException` (the pivot metadata has no single `foreignKey`/`localKey`).
  - No `attach`/`detach`/`sync` (write side is out of scope).
  - Single-column pivot/parent/related keys only — no composite keys.
  - Duplicate pivot rows yield duplicate related models (no `DISTINCT`).
  - Eager constraint closures may add `where`/`orderBy`/`limit` but **cannot**
    narrow the selected columns — the pivot path always selects `related.*` so
    the aliased pivot column can ride along.
  - Pivot values surface as flat **reserved** `pivot_*` attributes (including the
    link key `pivot_<foreignPivotKey>`) and appear in `toArray()`. A related
    column literally named `pivot_*` would be overwritten. These attributes are
    excluded from dirty-tracking on UPDATE, so re-saving a hydrated related model
    is safe; a forced re-INSERT would attempt to write the non-existent columns.
  - Null parent key: the eager path buckets a null parent key under null
    (relation resolves to `null`); the lazy path renders `… IS NULL` and returns
    pivot rows whose link column is NULL — a minor divergence.
