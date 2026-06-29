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

// Mass-create via insert()
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
Contact::find(1);                       // by primary key → single Model (or false)
Contact::find(['email' => 'a@x.com']);  // by attributes
Contact::findOne(['email' => 'a@x.com']);

Contact::first();                       // first row
Contact::all();                         // all rows → Collection
Contact::get();                         // all rows → Collection
Contact::get(['id', 'email']);          // only some columns

Contact::where('is_active', 1)->get();  // filtered → Collection
```

- A query returning **multiple** rows yields a [`Collection`](#collections).
- A single-row read (e.g. `find` by PK, `first`) yields a `Model`.
- An empty result yields `[]`.

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

### Joins

```php
Contact::query()
    ->join('orders', 'orders.contact_id', '=', 'contacts.id')
    ->leftJoin('notes', 'notes.contact_id', '=', 'contacts.id')
    ->get();
// also: rightJoin(), fullJoin(), crossJoin(), on(), orOn()
```

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
Contact::where('is_active', 1)->count();   // int|null
Contact::max('score');                     // mixed
Contact::min('score');                     // mixed
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
// On an existing model — updates dirty attributes only
$contact = Contact::find(1);
$contact->email = 'new@x.com';
$contact->save();

// Conditional bulk update — executes immediately
Contact::where('is_active', 0)->update(['status' => 'archived']);
```

> `update()` executes immediately (it is not chainable). Set conditions
> **before** calling it.

---

## Deleting records

```php
Contact::where('id', 1)->delete();         // DELETE ... WHERE id = 1

$contact = Contact::find(1);
$contact->delete();                        // deletes that row

Contact::destroy([1, 2, 3]);               // delete by primary keys
```

- A `delete()` that produces **no WHERE clause** throws
  `RuntimeException('SQL query is empty')` — a guard against wiping the table.
  Use a raw `TRUNCATE` if you really mean to empty it.
- With `$soft_deletes = true`, `delete()` sets `deleted_at` instead of removing
  the row.

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

---

## Attribute casting

Declare `$casts` to convert attribute values on read. Supported types:

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
`withCast()`:

```php
Contact::query()->withCast(['is_active' => 'bool'])->get();
```

---

## Relationships

Define relationships as methods on the model. The relation method returns a
query for the related model.

```php
class Deal extends Model
{
    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id', 'id');
    }
}

class Contact extends Model
{
    public function deals()
    {
        return $this->hasMany(Deal::class, 'contact_id', 'id');
    }

    public function profile()
    {
        return $this->hasOne(Profile::class, 'contact_id', 'id');
    }
}
```

Available: `hasOne()`, `hasMany()`, `belongsTo()`, `belongsToMany()` — each
takes `($model, $foreignKey = null, $localKey = null)`.

### Eager loading

```php
Contact::with('deals')->get();                   // load deals for each contact
Contact::with(['deals', 'profile'])->get();      // multiple relations

// Constrain the relation
Contact::with('deals', function ($q) {
    $q->where('status', 'open');
})->get();

// Alias the loaded relation
Contact::with('deals as open_deals')->get();

// Access after loading
foreach (Contact::with('deals')->get() as $contact) {
    foreach ($contact->deals as $deal) { /* ... */ }
}
```

---

## Relation aggregates & existence

```php
Contact::withCount('deals')->get();    // adds `deals_count`
Contact::withSum('deals.amount')->get();
Contact::withAvg('deals.amount')->get();
Contact::withMin('deals.amount')->get();
Contact::withMax('deals.amount')->get();
Contact::withExists('deals')->get();   // adds bool-cast `deals_exists`

// Filter by relation existence
Contact::whereHas('deals')->get();
Contact::whereHas('deals', fn ($q) => $q->where('status', 'open'))->get();

// Filter by existence AND eager-load the same relation
Contact::withWhereHas('deals', fn ($q) => $q->where('status', 'open'))->get();
```

Aggregate columns are aliased `<relation>_<function>` by default (e.g.
`deals_count`); use `'deals as x'` to alias.

---

## Model events

Models fire lifecycle events. Register handlers in a static `boot()` using the
event registrars. A pre-event (`saving`, `updating`, `deleting`) that returns
`false` aborts the operation.

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

        static::created(fn ($model) => Log::info("created {$model->id}"));

        // A handler can also be a class with a handle() method
        static::deleted(SyncDeletion::class);
    }
}
```

Events: `booting`, `booted`, `retrieved`, `saving`, `saved`, `creating`,
`created`, `updating`, `updated`, `deleting`, `deleted`.

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

Use `Schema` (a facade over `Blueprint`) to create, alter and drop tables. The
table prefix is applied automatically.

```php
use BitApps\WPDatabase\Schema;

Schema::create('contacts', function ($table) {
    $table->id();                                   // BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
    $table->string('email');                        // VARCHAR(255)
    $table->string('first_name')->nullable();
    $table->int('age')->unsigned()->defaultValue(0);
    $table->text('bio')->nullable();
    $table->bool('is_active')->defaultValue(1);
    $table->json('meta')->nullable();

    $table->unique('email');                        // unique index
    $table->unique(['first_name', 'last_name']);    // composite unique
    $table->index();                                // index last column

    $table->timestamps();                           // created_at + updated_at
    $table->softDeletes();                          // deleted_at
});
```

### Column types

`char`, `varchar`, `json`, `binary`, `varbinary`, `tinyblob`, `tinytext`,
`text`, `blob`, `mediumtext`, `mediumblob`, `longtext`, `longblob`, `enum`,
`set`, `bit`, `tinyint`, `bool`/`boolean`, `smallint`, `mediumint`,
`int`/`integer`, `bigint`, `float`, `double`, `double_precision`, `decimal`/
`dec`, `date`, `datetime`, `timestamp`, `time`, `year`. Helpers: `id()`,
`increments()`, `string()`, `timestamps()`, `softDeletes()`.

### Column modifiers

```php
$table->int('views')->unsigned()->zeroFill();
$table->string('slug')->nullable()->defaultValue('');
$table->bigint('user_id')->primary();
$table->decimal('price')->length([10, 2]);
```

`nullable()`, `defaultValue($v)`, `unsigned()`, `zeroFill()`, `primary()`,
`unique($column = null)`, `index($type = null)`, `length($n)`.

### Foreign keys

```php
Schema::create('deals', function ($table) {
    $table->id();
    $table->bigint('contact_id')->unsigned();
    $table->foreign('contacts', 'id')->onDelete()->cascade();
    // FK on the previously-defined column (contact_id) → contacts(id)
});
```

`foreign($referencedTable, $referencedColumn)` applies to the **last defined
column**, then one of `cascade()`, `restrict()`, `setNull()`, optionally scoped
by `onDelete()` / `onUpdate()`.

### Altering & dropping

```php
Schema::drop('contacts');
Schema::rename('contacts', 'people');

Schema::edit('contacts', function ($table) {
    $table->string('phone')->nullable();   // ADD COLUMN
    $table->dropColumn('bio');
    $table->dropTimestamps();
    $table->dropIndex(['email_UNIQUE']);
    $table->dropForeign(['fk_name']);
    $table->dropPrimary();
});

// Scope to a custom prefix
Schema::withPrefix('wp_other_')->create('logs', function ($table) { /* ... */ });
```

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
