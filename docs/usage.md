# wp-database — Usage Guide

A small ActiveRecord ORM + fluent query/schema builder on top of WordPress'
`$wpdb`. Models map to tables, queries are built fluently, results come back as
hydrated model objects inside a `Collection`.

Intended audience: WordPress plugin developers comfortable with PHP and Composer.
The API is modelled on Eloquent's; prior Eloquent experience is helpful but not
required — every method used in this guide is documented in full here.

- [Install](#install)
- [Setup](#setup)
- [Defining models](#defining-models)
- [Creating records](#creating-records)
- [Reading records](#reading-records)
- [The query builder](#the-query-builder)
- [Collections](#collections)
- [Updating records](#updating-records)
- [Deleting records](#deleting-records)
- [Upsert](#upsert)
- [Attribute casting](#attribute-casting)
- [Relationships](#relationships)
- [Relation aggregates & existence](#relation-aggregates--existence)
- [Model events](#model-events)
- [Transactions](#transactions)
- [Raw queries](#raw-queries)
- [Schema builder](#schema-builder)
- [Static vs instance calls (IDE notes)](#static-vs-instance-calls-ide-notes)
- [Breaking changes](#breaking-changes)
- [Limitations & known issues](#limitations--known-issues)

---

## Install

Add the repository and require the package:

```jsonc
// composer.json
"repositories": [
  { "type": "vcs", "url": "https://github.com/Bit-Apps-Pro/wp-database" }
]
```

```bash
composer require bitapps/wp-database:dev-main
```

Namespace: `BitApps\WPDatabase`. (When bundled into a plugin with Strauss/
php-scoper the namespace is prefixed, e.g. `BitApps\YourPlugin\Deps\BitApps\WPDatabase`.)

---

## Setup

The package wraps the global `$wpdb`, so it works inside WordPress with no
connection config. Optionally set a plugin-specific table prefix once during
boot — it is appended after the WordPress prefix (`wp_` + plugin prefix):

```php
use BitApps\WPDatabase\Connection;

Connection::setPluginPrefix('myplugin_'); // tables resolve as wp_myplugin_*
```

Other `Connection` helpers:

```php
Connection::getPrefix();   // wp_ + plugin prefix
Connection::wpPrefix();    // wp_
Connection::enableQuery(); // start collecting executed queries
Connection::queries();     // array of executed queries (after enableQuery)
Connection::errors();      // array of query errors
```

You can also pin a timezone for generated timestamps (`created_at`/`updated_at`):

```php
use BitApps\WPDatabase\QueryBuilder;

QueryBuilder::$TIME_ZONE = 'UTC'; // otherwise wp_timezone_string()/gmt_offset is used
```

---

## Defining models

Extend `Model`. Table name is auto-derived from the class name (pluralised,
snake_cased) unless `$table` is set. The WordPress + plugin prefix is added
automatically.

```php
use BitApps\WPDatabase\Model;

class Contact extends Model
{
    protected $table = 'contacts';   // optional; default derived from class name
    protected $primaryKey = 'id';    // default 'id'
    protected $prefix = '';          // overrides the plugin prefix when non-empty

    public $timestamps = true;       // auto-maintain created_at / updated_at

    // Mass-assignment allow-list. Omit to allow all attributes.
    protected $fillable = ['first_name', 'last_name', 'email', 'meta'];

    // Attribute casts (see "Attribute casting").
    protected $casts = [
        'meta'      => 'array',
        'is_active' => 'bool',
    ];

    // Soft deletes: delete() sets deleted_at instead of removing the row.
    // NOTE: reads are NOT filtered — soft-deleted rows appear in all() and every query.
    public $soft_deletes = true;
}
```

| Property | Purpose |
|---|---|
| `$table` | Table name (without prefix). Auto-derived from the class name (pluralised, snake_cased) if unset. |
| `$primaryKey` | Primary key column (default `id`). |
| `$prefix` | When non-empty, **replaces** the plugin prefix for this model only. Table resolves to `$wpdb->prefix . $prefix . $tableName`. Leave empty (the default) to use the plugin prefix set via `Connection::setPluginPrefix()`. |
| `$fillable` | Mass-assignment allow-list. Unset = allow all non-timestamp, non-PK attributes. |
| `$casts` | Map of column → cast type. See [Attribute casting](#attribute-casting). |
| `$timestamps` | Auto-set `created_at`/`updated_at` on insert/update (declared `true` in the base `Model`; set to `false` to disable). Requires the columns to exist — see [`timestamps()` in `docs/schema.md`](schema.md#timestamps). |
| `$soft_deletes` | Must be declared `true` on the model. `delete()` then sets `deleted_at` instead of removing the row. **Reads are not filtered** — soft-deleted rows are returned by `all()` and every query. See [Limitations](#limitations--known-issues) and [`softDeletes()` in `docs/schema.md`](schema.md#softdeletes). |

---

## Creating records

```php
// Instantiate, set, save
$contact = new Contact();
$contact->first_name = 'Ada';
$contact->email = 'ada@example.com';
$saved = $contact->save();        // returns the saved Model on success, false on failure

// Single-row insert — returns the created Model on success, false on failure
$contact = Contact::insert([
    'first_name' => 'Ada',
    'email'      => 'ada@example.com',
]);

// Bulk insert (array of rows) — returns a Collection of created models
$created = Contact::insert([
    ['first_name' => 'Ada',  'email' => 'ada@x.com'],
    ['first_name' => 'Grace','email' => 'grace@x.com'],
]);
```

`save()` inserts when the model is new and updates (dirty attributes only) when
it already exists. On success it returns the model; on failure, `false`.

---

## Reading records

```php
// find() applies WHERE conditions and returns a Collection — not a single Model
Contact::find(1);                        // by PK → Collection (or [] if none)
Contact::find(['email' => 'a@x.com']);   // by attributes → Collection

// Use findOne() or first() when you want exactly one Model back
Contact::findOne(['email' => 'a@x.com']); // → single Model or []
Contact::where('email', 'a@x.com')->first(); // → single Model or []

Contact::first();                        // first row → single Model or []
Contact::all();                          // all rows → Collection
Contact::get();                          // all rows → Collection
Contact::get(['id', 'email']);           // only some columns

Contact::where('is_active', 1)->get();   // filtered → Collection
```

- `find()` sets WHERE conditions and calls `get()` — it always returns a **`Collection`**
  (or `[]` when the result is empty, `false` on a database error). It does **not** return a
  single Model, even when called with a single primary key.
- For a single-row read use `findOne(['col' => $val])` or chain `->first()` on a builder.
  Both call `LIMIT 1` internally and return a `Model` on success or `[]` on miss.
- A query returning multiple rows returns a [`Collection`](#collections).

---

## The query builder

Every static call on a model opens a builder; chain freely. Use `toSql()` to
inspect the SQL without executing.

### Select

```php
Contact::select('id', 'email')->get();
Contact::select(['id', 'email'])->get();
Contact::addSelect('phone')->get();                 // add to existing select
Contact::selectRaw('COUNT(*) as total')->get();     // raw expression (NOT select())
Contact::selectRaw('SUM(amount) as amt', [])->get();
```

> Pass raw SQL expressions to `selectRaw()`, not `select()` — `select()`
> back-tick-quotes its arguments as column identifiers.

### Where

```php
Contact::where('email', 'a@x.com')->get();              // col = value
Contact::where('age', '>=', 18)->get();                 // col op value
Contact::where('id', [1, 2, 3])->get();                 // value array → IN (...)
Contact::where('deleted_at', null)->get();              // null → IS NULL

Contact::where('a', 1)->where('b', 2)->get();           // AND
Contact::where('a', 1)->orWhere('b', 2)->get();         // OR

// Grouped / nested conditions via a closure
Contact::where('active', 1)
    ->where(function ($q) {
        $q->where('city', 'NYC')->orWhere('city', 'LA');
    })
    ->get();

// Helpers
Contact::whereIn('id', [1, 2, 3])->get();
Contact::whereNull('deleted_at')->get();
Contact::whereNotNull('email')->get();
Contact::whereBetween('age', 18, 65)->get();
Contact::orWhereBetween('score', 0, 50)->get();

// LIKE — pass the operator explicitly; the value is bound safely via %s
Contact::where('email', 'LIKE', '%@x.com')->get();

// Raw
Contact::whereRaw('YEAR(created_at) = %d', [2026])->get();
Contact::orWhereRaw('email LIKE %s', ['%@x.com'])->get();
```

### Conditional clauses

```php
Contact::query()
    ->when($search, fn ($q, $search) => $q->whereRaw('name LIKE %s', ["%{$search}%"]))
    ->when($onlyActive, fn ($q) => $q->where('is_active', 1), fn ($q) => $q->where('is_active', 0))
    ->get();
```

### Ordering, grouping, having

```php
Contact::orderBy('created_at')->desc()->get();
Contact::orderBy('name')->asc()->get();
Contact::orderByRaw('FIELD(status, %s, %s)', ['new', 'open'])->get();

Contact::groupBy('city')->having('city', '!=', '')->get();
```

> A bare `desc()` or `asc()` call without a prior `orderBy()` defaults the order
> column to the model's primary key — e.g. `Contact::desc()->get()` emits
> `ORDER BY id DESC`.

### Joins

```php
Contact::query()
    ->join('orders', 'orders.contact_id', '=', 'contacts.id')
    ->leftJoin('notes', 'notes.contact_id', '=', 'contacts.id')
    ->get();
// also: rightJoin(), fullJoin(), crossJoin(), on(), orOn()
```

> **Joins are currently unreliable.** The join implementation has known bugs:
> the joined table name is double-prefixed (`wp_wp_*`), ON-clause columns are not
> adjusted when an alias is present, and `prepareOn` reuses the mutated column
> value for the second-column lookup. Use raw SQL (`whereRaw` / `raw()`) until
> these are fixed. See [Limitations](#limitations--known-issues).

### Limit / offset / pagination

```php
Contact::take(10)->skip(20)->get();      // LIMIT 10 OFFSET 20

$page = Contact::where('is_active', 1)->paginate($pageNo = 1, $perPage = 20);
// [
//   'data'          => Collection,
//   'pages'         => 5,
//   'total'         => 93,
//   'current_total' => 20,
//   'current_page'  => 1,
//   'last_page'     => 5,
//   'per_page'      => 20,
// ]
```

### Aggregates

```php
Contact::where('is_active', 1)->count();   // int — always (returns 0, not null, on no rows)
Contact::max('score');                     // mixed|null — null when the result set is empty
Contact::min('score');                     // mixed|null — null when the result set is empty
```

### Inspecting the SQL

```php
Contact::where('is_active', 1)->toSql();   // build the SQL string, do not run
Contact::where('is_active', 1)->prepare(); // prepared SQL (placeholders bound)
```

---

## Collections

Multi-row reads return `BitApps\WPDatabase\Collection`, which implements
`ArrayAccess`, `IteratorAggregate`, `Countable` and `JsonSerializable` — so
`foreach`, `$c[0]`, `count($c)` and `json_encode($c)` all work.

```php
$contacts = Contact::where('is_active', 1)->get();

// Wrap an existing array in a Collection
$collection = Collection::make($items);

$contacts->map(fn ($c) => $c->email);
$contacts->filter(fn ($c) => $c->score > 50);
$contacts->pluck('email');          // Collection of emails
$contacts->first();                 // first item (optional callback + default)
$contacts->last();
$contacts->reduce(fn ($carry, $c) => $carry + $c->score, 0);
$contacts->reverse();
$contacts->all();                   // underlying plain array
$contacts->toArray();               // array of model arrays
$contacts->count();
```

---

## Updating records

```php
// On an existing model — set attributes then save; returns Model or false
$contact = Contact::findOne(['id' => 1]);
$contact->email = 'new@x.com';
$contact->save();        // returns the Model on success, false on failure

// Conditional bulk update — executes immediately, returns int (rows affected) or false
Contact::where('is_active', 0)->update(['status' => 'archived']);
```

> `update()` on a builder executes immediately (it is not chainable). Set conditions
> **before** calling it. When updating a model instance through `save()`, the return
> value is the `Model` on success or `false` on failure.

---

## Deleting records

```php
Contact::where('id', 1)->delete();         // DELETE ... WHERE id = 1

$contact = Contact::findOne(['id' => 1]);
$contact->delete();                        // deletes that row

Contact::destroy([1, 2, 3]);               // delete by primary keys
```

- A `delete()` that produces **no WHERE clause** throws
  `RuntimeException('SQL query is empty')` — a guard against wiping the table.
  Use a raw `TRUNCATE` if you really mean to empty it.
- With `$soft_deletes = true`, `delete()` sets `deleted_at` instead of removing
  the row. **Reads are not filtered** — soft-deleted rows are returned by `all()`
  and every query; there is no automatic scope. See [Limitations](#limitations--known-issues).

---

## Upsert

Insert rows, updating on duplicate key. Pass a single row or an array of rows;
the optional second argument lists the columns to update on conflict (defaults
to all provided columns).

```php
Contact::query()->upsert(
    ['email' => 'a@x.com', 'first_name' => 'Ada'],          // unique key: email
    ['first_name']                                          // columns to update on duplicate
);

Contact::query()->upsert([
    ['email' => 'a@x.com', 'first_name' => 'Ada'],
    ['email' => 'b@x.com', 'first_name' => 'Bob'],
]);
```

> **MySQL only** — `upsert()` emits `INSERT … ON DUPLICATE KEY UPDATE` and will not
> work on other databases.
>
> **`updated_at` quirk** — when `updated_at` is in the conflict-update set, the
> generated SQL maps `updated_at = VALUES(created_at)` rather than
> `VALUES(updated_at)`. This is a known implementation detail; the timestamp will
> reflect the value written to `created_at` at insert time, not a separate
> `updated_at` value. See [Limitations](#limitations--known-issues).

---

## Attribute casting

Declare `$casts` to convert attribute values during `fill()` — both when hydrating query results and during mass-assignment. Supported types:

| Cast | Result |
|---|---|
| `int` | `(int)` value |
| `string` | `(string)` value |
| `bool` / `boolean` | `(bool)` value |
| `array` | `json_decode($value, true)` |
| `object` | `json_decode($value)` |
| `date` | `DateTime` from `Y-m-d H:i:s` |

```php
protected $casts = [
    'meta'       => 'array',
    'is_active'  => 'bool',
    'created_at' => 'date',
];
```

`NULL` values are returned as `null` (not cast). Add casts at runtime with
`withCast()`, which returns the builder and is chainable:

```php
Contact::query()->withCast(['is_active' => 'bool'])->get();
```

---

## Relationships

Define relationships as methods on the model. The relation method returns a
query for the related model.

**Key convention:** `$foreignKey` is the column on the *related* model's table;
`$localKey` is the column on the *calling* model's table. Always supply both
explicitly when your column names differ from the ORM default
(`{callerTable}_id` / `id`).

```php
class Contact extends Model
{
    public function deals()
    {
        // foreignKey='contact_id' lives on the deals table
        // localKey='id' lives on the contacts table
        // Generated predicate: WHERE deals.contact_id IN (SELECT id FROM contacts)
        return $this->hasMany(Deal::class, 'contact_id', 'id');
    }

    public function profile()
    {
        // foreignKey='contact_id' on the profiles table
        // localKey='id' on the contacts table
        return $this->hasOne(Profile::class, 'contact_id', 'id');
    }
}

class Deal extends Model
{
    public function contact()
    {
        // Deal.contact_id references Contact.id
        // foreignKey='id' lives on the contacts (related) table
        // localKey='contact_id' lives on the deals (this) table
        // Generated predicate: WHERE contacts.id IN (SELECT contact_id FROM deals)
        return $this->belongsTo(Contact::class, 'id', 'contact_id');
    }
}
```

> **`hasOne` and `belongsTo` are identical.** `hasOne()` is a direct alias of
> `belongsTo()` — both set the `oneToOne` relation type and return a single
> model. There is no separate reverse-direction implementation; the direction is
> determined entirely by the keys you provide and which model calls the method.

Available: `hasOne()`, `hasMany()`, `belongsTo()`, `belongsToMany()` — each
takes `($model, $foreignKey = null, $localKey = null)`.

> **`belongsToMany` is non-functional for pivot tables.** The method is
> declared but contains no pivot-table join logic. Calling it will not produce a
> many-to-many query through an intermediate table. See
> [Limitations](#limitations--known-issues).

### Eager loading

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
(e.g. `deals_count`, `deals_sum`). Pass `'relation as alias'` to use a custom
column name:

```php
Contact::withCount('deals as total_deals')->get();   // adds `total_deals`
Contact::withSum('deals.amount as revenue')->get();  // adds `revenue`
```

For `withMin`, `withMax`, `withAvg`, and `withSum` the column to aggregate must
be specified as `'relation.column'`; passing just the relation name defaults to
`*`, which is meaningful only for `withCount` and `withExists`.

---

## Model events

Models fire lifecycle events. Register handlers in a static `boot()` using the
event registrars. A pre-event (`saving`, `updating`, `deleting`) that returns
`false` aborts the operation. `saving`/`saved` fire on both insert and update.

```php
class Contact extends Model
{
    protected static function boot()
    {
        static::saving(function ($model) {
            if (!$model->email) {
                return false;     // abort the save
            }
        });

        static::saved(fn ($model) => Log::info("saved {$model->id}"));

        // A handler can also be a class with a handle() method
        static::deleted(SyncDeletion::class);
    }
}
```

**Subscribable events** (registered via Closure using the methods below):
`retrieved`, `saving`, `saved`, `updating`, `updated`, `deleting`, `deleted`.

**Boot hooks** — override these protected static methods instead of registering
a Closure: `booting()` (runs before `boot()`), `booted()` (runs after `boot()`).
The `boot()` method itself is the standard entry point for registering event
handlers.

> `creating`/`created` events fire internally but have no public registrar —
> they cannot be subscribed via `static::creating(...)` / `static::created(...)`.
> Use `saving`/`saved` instead, which fire on both insert and update.

> The `HasEvents` trait reserves the method names `boot`, `booting`, `booted`,
> `fireEvent`, `fireCustomEvent`, `registerEvent` and the properties `$events`,
> `$registeredEvents`, `$booted`.

---

## Transactions

```php
use BitApps\WPDatabase\Connection;

Connection::startTransaction();
try {
    Contact::insert([...]);
    Deal::insert([...]);
    Connection::commit();
} catch (\Throwable $e) {
    Connection::rollback();
    throw $e;
}
```

> The equivalent methods on `QueryBuilder` are deprecated — use `Connection`.

---

## Raw queries

```php
// SELECT → returns result rows
Contact::query()->raw('SELECT * FROM wp_contacts WHERE id = %d', [1]);

// Non-SELECT → returns the wpdb query result
Contact::query()->raw('UPDATE wp_contacts SET is_active = 1 WHERE id = %d', [1]);
```

Placeholders use `$wpdb` conventions (`%d`, `%s`, `%f`) with the bindings array.

---

## Schema builder

Use `Schema` (a facade over `Blueprint`) to create, alter and drop tables. By
default the table name is used as-is — call `Schema::withPrefix($prefix)` to
apply a prefix.

```php
use BitApps\WPDatabase\Schema;

Schema::create('contacts', function ($table) {
    $table->id();
    $table->string('email');
    $table->string('first_name')->nullable();
    $table->bool('is_active')->defaultValue(1);
    $table->timestamps();
});
```

For the full reference — column types, modifiers, foreign keys, `Schema::edit`,
`Schema::drop`, `Schema::rename`, and prefix scoping — see
[Schema builder reference](schema.md).

---

## Static vs instance calls (IDE notes)

All builder/relation methods can be called three ways:

```php
Contact::where('id', 1)->get();          // static (magic via __callStatic)
Contact::query()->where('id', 1)->get(); // real static entry — best IDE support
(new Contact())->where('id', 1)->get();  // instance
```

- `Model::query()` is a **real** static method returning a `QueryBuilder`; start
  chains with it for autocomplete **and** Ctrl/Cmd+Click navigation that work in
  every editor (PhpStorm and VS Code/Intelephense).
- Terse static calls (`Contact::where()`) are forwarded through
  `__call`/`__callStatic`. `@method static` docblocks give autocomplete; the
  `@mixin QueryBuilder` annotation gives PhpStorm navigation to the real method.

---

## Breaking changes

If you are upgrading, read [breaking-changes.md](breaking-changes.md) — it
covers the `Collection` return type, `update()`/`save()` behavior, `select()`
quoting, the `delete()`-without-WHERE guard, null casting, and the relation
method relocation.

---

## Limitations & known issues

- **Joins are broadly unreliable.** `join()` in `QueryBuilder` always prepends
  `Connection::wpPrefix()` (plus the model prefix) onto the supplied table name,
  causing a double prefix (`wp_wp_*`) unconditionally — not only when a plugin
  prefix is set. Separately, `prepareOn()` reuses the mutated column value for the
  second-column lookup, and ON-clause columns are not adjusted when an alias is
  present. Workaround: write raw JOIN clauses via `raw()` and apply your own
  prefix via `Connection::getPrefix()`.

- **`belongsToMany` is declared but non-functional.** The method exists but
  contains no pivot-table join logic. Calling it does not produce a
  many-to-many query through an intermediate table. Workaround: model the pivot
  as an explicit intermediate model with `hasMany` on each side.

- **`belongsTo` and `hasOne` are the same alias; key naming is reversed from
  Laravel.** Both set the same `oneToOne` relation. The `$foreignKey` argument
  is the column on the **related** table and `$localKey` is the column on the
  **calling** model's table — the opposite of Laravel's convention. Ensure you
  supply both arguments explicitly to avoid confusion.

- **Soft delete is write-only.** `softDeletes()` adds a `deleted_at` column and
  `delete()` sets it, but there is no global scope to filter soft-deleted rows
  from queries. Every `get()` / `find()` returns soft-deleted rows alongside
  live ones. Workaround: add `->whereNull('deleted_at')` to every read query.

- **`upsert` is MySQL-only.** It generates `INSERT … ON DUPLICATE KEY UPDATE`,
  which is not portable to other databases. Additionally, the generated SQL sets
  `updated_at = VALUES(created_at)` instead of `VALUES(updated_at)`, so the
  timestamp may be wrong on update. Workaround: use separate `insert` + `update`
  calls where portability or correct timestamps are required.

- **`creating`/`created` events cannot be subscribed.** These event names fire
  internally during `insert()` but `HasEvents` provides no registrar methods for
  them. Calling `static::creating(fn ...)` or `static::created(fn ...)` in
  `boot()` will throw a fatal error. Use `saving`/`saved` instead — they fire
  on both insert and update.

- **Bulk `insert()` may return a bare array, not a `Collection`.** When the
  post-insert re-query that hydrates the inserted rows fails, the fallback path
  returns a plain PHP array of IDs rather than a `Collection`. Code that calls
  Collection methods on the return value of `insert()` will break in that case.
  Workaround: check `is_array()` on the result or use `Collection::make()` to
  wrap it defensively.

- **Schema builder does not auto-apply the table prefix.** `Schema::$prefix`
  defaults to `null`, not `''`; table names are used as-is unless you call
  `Schema::withPrefix()` explicitly. See [Schema builder reference](schema.md).

