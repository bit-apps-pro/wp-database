# Breaking Changes — v2.0

This release (next tag after `1.11`) contains **backward-incompatible** changes
to the query builder, model, relations and schema blueprint. Because the public
API and runtime behavior change, it is a **major** version bump.

- [1. Summary](#1-summary)
- [2. Breaking changes](#2-breaking-changes)
- [3. Behavioral changes](#3-behavioral-changes)
- [4. New features](#4-new-features)
- [5. Deprecations](#5-deprecations)
- [6. Migration checklist](#6-migration-checklist)

---

## 1. Summary

| # | Area | Change | Impact |
|---|------|--------|--------|
| 1 | Model | `get()` returns `Collection`, not `array` | High |
| 2 | QueryBuilder | `update()` no longer chainable, runs immediately | High |
| 3 | QueryBuilder | `save()` returns `Model\|false` (was `bool`/`int`) | High |
| 4 | QueryBuilder | `select()` back-tick quotes columns; raw exprs break | High |
| 5 | QueryBuilder | `delete()` with no `WHERE` throws (was: table wipe) | High |
| 6 | Model | NULL values no longer cast | Medium |
| 7 | QueryBuilder | `raw()`/`exec()` non-SELECT return changed | Medium |
| 8 | QueryBuilder | `with()` signature changed (old `Closure`-only form gone) | Medium |
| 9 | QueryBuilder | `withCount()` removed → relation aggregate | Medium |
| 10 | Relations | `addRelation()` signature `string` → `array`, void | Medium |
| 11 | Blueprint | `binary()` removed | Low |
| 12 | QueryBuilder | exception type/message changed in `exec()` | Low |

---

## 2. Breaking changes

### 2.1 `Model::get()` / query results return a `Collection`, not an `array`

Multi-row reads now return `BitApps\WPDatabase\Collection` instead of a plain
PHP array. `find()` always returns a `Collection` (even for a single PK); use
`findOne()` or `->first()` for a single model. Empty results return `[]`.

```php
// Before
$users = User::where('active', 1)->get(); // array<User>
is_array($users);        // true
array_map($fn, $users);  // OK

// After
$users = User::where('active', 1)->get(); // Collection<User>
is_array($users);        // false  ❌
array_map($fn, $users);  // TypeError ❌
```

**Why it breaks:** `is_array()` checks fail; native `array_*` functions reject
the object.

**Migration:** `Collection` implements `ArrayAccess`, `IteratorAggregate`,
`Countable`, `JsonSerializable` — so `foreach`, `$users[0]`, `count($users)` and
`json_encode($users)` keep working. Replace native array calls with collection
methods:

```php
$users->map($fn);     // instead of array_map
$users->filter($fn);  // instead of array_filter
$users->pluck('id');  // column extraction
$users->first();      // first item (or matching callback)
$users->last();
$users->reduce($fn, $initial);
$users->all();        // underlying plain array
$users->toArray();    // array of model arrays
```

---

### 2.2 `QueryBuilder::update()` is no longer chainable and executes immediately

```php
// Before — returned $this, deferred execution
$qb->update(['name' => 'x'])->where('id', 1)->save();

// After — executes now, returns result (not $this)
User::where('id', 1)->update(['name' => 'x']); // runs UPDATE immediately
```

If the bound model **already exists**, `update()` now delegates to `save()`
(dirty-attribute update). On a fresh model it builds the update from all
attributes and executes.

**Why it breaks:** any chain calling a method *after* `update()` breaks —
`update()` no longer returns the builder.

**Migration:** set conditions **before** `update()`; drop trailing
`->save()`/`->exec()`.

---

### 2.3 `QueryBuilder::save()` return value changed

```php
// Before
$result = $model->save();
// insert -> bool(true) ; update -> int rows_affected

// After
$result = $model->save();
// success -> the Model instance ; failure -> bool(false)
```

**Why it breaks:** code relying on `true` / `rows_affected` int now receives a
Model object on success.

**Migration:** treat the return as truthy for success; read affected rows via
`Connection::prop('rows_affected')` if needed.

---

### 2.4 `select()` back-tick qualifies columns — raw expressions break

`select()` and `addSelect()` pass every column through `prepareColumnName()`,
which wraps the name as `` `table`.`column` `` unless it already contains a `.`.

```php
// Before
->select('COUNT(*) as total')   // emitted: COUNT(*) as total

// After
->select('COUNT(*) as total')   // emitted: `COUNT(*) as total`  ❌ invalid SQL
```

**Why it breaks:** any raw SQL expression, function call, or alias passed to
`select()` is now quoted as a single identifier.

**Migration:** use `selectRaw()` for expressions; plain columns need no change:

```php
->selectRaw('COUNT(*) as total')
->selectRaw('SUM(amount) as amt', $bindings)
```

---

### 2.5 `delete()` with no `WHERE` clause no longer wipes the table

A delete producing no `WHERE` clause now returns empty SQL, and `exec()` throws
instead of running `DELETE FROM table` (which previously emptied the table).

```php
// Before
User::delete();   // ⚠️ deleted every row

// After
User::delete();   // throws RuntimeException('SQL query is empty')
```

**Why it breaks (intentionally):** prevents accidental full-table deletes.

**Migration:** add an explicit condition — `User::where('id', $id)->delete()`. To
truly empty a table, use a raw `TRUNCATE`/`DELETE` query.

---

### 2.6 NULL values are no longer cast

`castTo()` short-circuits and returns `null` when the value is `null`, instead of
running it through the configured cast.

```php
// casts = ['count' => 'int', 'flag' => 'bool']

// Before
$m->count;  // null -> (int) null  => 0
$m->flag;   // null -> false

// After
$m->count;  // null  (unchanged)
$m->flag;   // null  (unchanged)
```

**Why it breaks:** nullable cast columns now surface `null` rather than the
zero-value (`0`, `false`, `''`, epoch `DateTime`).

**Migration:** null-check or coalesce — `$m->count ?? 0`.

---

### 2.7 `raw()` / `exec()` return value for non-SELECT queries

`raw()` returns the last result set **only** for `SELECT` queries. For
non-SELECT statements (INSERT/UPDATE/DELETE/DDL) it returns the underlying
`Connection::query()` result instead of `last_result`.

**Migration:** for write statements, check truthiness / `Connection::prop()`
rather than expecting a result set.

---

### 2.8 `QueryBuilder::with()` signature changed

`with()` stays on `QueryBuilder` but gains a richer signature and is also
reachable statically/instance via the model (see §4.4).

```php
// Before
public function with($relation)                   // string | Closure

// After
public function with($relation, $callback = null) // string | array, optional Closure
```

Eager-loading works in every call style:

```php
User::with('posts')->get();
User::with('posts', fn ($q) => $q->where('published', 1))->get();
User::with(['posts', 'profile'])->get();
```

**Why it breaks:** the old single-arg `Closure` form (`with(fn ($q) => …)`) is
gone — pass the relation name first, the constraint second.

---

### 2.9 `QueryBuilder::withCount()` removed — now a relation aggregate

The old no-arg `withCount()` that appended `COUNT(*) as count` to the select is
gone. `withCount()` now lives in `Relations`, **requires a relation name**, and
emits a correlated sub-select.

```php
// Before
->withCount()->get();              // COUNT(*) as count

// After
User::withCount('posts')->get();   // adds posts_count sub-select
```

**Migration:** for a plain row count use `->count()`; for relation counts use
`withCount($relation)`. See §4.2 for the full aggregate family.

---

### 2.10 `addRelation()` signature changed

```php
// Before
public function addRelation($relation)        // string; resolved method, returned query

// After
public function addRelation(array $relation)  // merges relation array, returns void
```

**Why it breaks:** callers passing a string, or using the return value, break.

**Migration:** use `with()` / the new relation methods instead of calling
`addRelation()` directly.

---

### 2.11 `Blueprint::binary()` removed

The `binary()` column modifier was deleted.

```php
$table->string('hash')->binary();  // ❌ Call to undefined method
```

**Migration:** express the binary/collation requirement another way (e.g. a raw
column type) until a replacement is provided.

---

### 2.12 Exception type and message changed in `exec()`

A null/empty prepared query now throws `RuntimeException('SQL query is empty')`
instead of `Exception('SQL query is null')`.

`RuntimeException` extends `Exception`, so `catch (\Exception $e)` still works.
Code matching on the **message** string, or catching the base `Exception` type
specifically for this case, should be reviewed.

---

## 3. Behavioral changes

Not signature breaks, but observable runtime differences.

- **`count()` now counts the primary key** (`COUNT(pk)`) via the new
  `aggregate()` helper, instead of `COUNT(*)`. Rows with a NULL primary key are
  no longer counted. Returns `int`; a real `0` is preserved (not coerced to `null`).
- **`min()` / `max()` run on a clone** of the builder, so calling them no longer
  mutates the current query's `select`/`selectRaw` state.
- **Bulk insert id collection fixed** — inserted ids are now derived from
  `rows_affected` starting at `lastInsertId()` (previously off by one / partial).
  Bulk inserts now return all created models.
- **NULL columns persist as SQL `NULL`** in insert/update/upsert, instead of
  being coerced to an empty string `''`.
- **`paginate()`** defaults `select` to `*` when empty and computes the count
  before applying limit/offset; pagination with explicit `select` columns and
  count no longer conflict.
- **`Model` now fires lifecycle events** during read/write — see §4.3. A model
  with custom logic in overridden write paths may observe new event callbacks.
- **Internal layout:** the three traits moved to `BitApps\WPDatabase\Concerns`
  and SELECT compilation extracted to `BitApps\WPDatabase\Query\Grammar`. Public
  classes are unchanged; only code importing those internals directly is affected.

---

## 4. New features

### 4.1 `Collection`

Returned from multi-row reads. Methods: `make()`, `all()`, `map()`, `filter()`,
`reduce()`, `pluck()`, `first()`, `last()`, `reverse()`, `toArray()`, plus
`Countable` / `ArrayAccess` / `IteratorAggregate` / `JsonSerializable`.

### 4.2 Relation aggregates & existence

Added to the `Relations` trait (all usable statically via the model):

```php
User::withCount('posts')->get();          // posts_count
User::withMin('posts.score')->get();
User::withMax('posts.score')->get();
User::withAvg('posts.score')->get();
User::withSum('posts.score')->get();
User::withExists('posts')->get();         // bool-cast posts_exists
User::whereHas('posts')->get();           // filter by existence
User::whereHas('posts', fn ($q) => $q->where('published', 1))->get();
User::withWhereHas('posts', $cb)->get();  // filter + eager-load
```

Relation names support an `as` alias: `withCount('posts as total_posts')`.

### 4.3 Model events (`HasEvents` trait)

**Subscribable events** — register in a model's `boot()` via the named static
registrars: `retrieved`, `saving`, `saved`, `updating`, `updated`, `deleting`,
`deleted`. **Boot hooks** — override the protected static methods `booting()`
(runs before `boot()`) and `booted()` (runs after) instead of using a registrar.

```php
protected static function boot()
{
    static::saving(fn ($model) => /* ... */);
    static::deleted(MyDeletedHandler::class); // class with handle()
}
```

A pre-event (`saving`/`updating`/`deleting`) returning `false` aborts the query.

> `creating`/`created` events fire internally but have no public registrar —
> use `saving`/`saved` instead, which fire on both insert and update.

> ⚠️ The trait **reserves** the method names `boot`, `booting`, `booted`,
> `fireEvent`, `fireCustomEvent`, `registerEvent` and the properties `$events`,
> `$registeredEvents`, `$booted` on subclasses. A child model already defining
> any of these will collide.

### 4.4 Relation eager-loading lives on `QueryBuilder` (callable statically)

`with()`, `withCount()`, `withMin/Max/Avg/Sum/Exists()`, `whereHas()`,
`withWhereHas()` are defined on `QueryBuilder` and forwarded from the model via
`__call`/`__callStatic`. All three call styles work and produce identical SQL:

```php
User::with('posts')->get();                        // static
User::where('id', '>', 0)->with('posts')->get();   // chained on the builder
(new User())->with('posts')->get();                // instance
```

> **Why on the builder, not the model:** PHP only routes through `__callStatic`
> for methods that are *not* declared on the class. While these methods lived on
> the model's `Relations` trait, `Model::with(...)` threw
> `Error: Non-static method ...::with() cannot be called statically`. Moving
> them to `QueryBuilder` (where `where()`/`select()` already live) restores
> static access. Relation *definitions* (`hasMany`, `belongsTo`, …) remain on
> the model.

**Return type:** these now return a `QueryBuilder` (previously the trait
versions returned the `Model`). Both are chainable to `->get()`, and forward
unknown methods to each other, so existing chains keep working.

**IDE navigation:** `Model` carries `@mixin \BitApps\WPDatabase\QueryBuilder`
(instead of a hand-maintained `@method static` list), so Ctrl/Cmd+Click on a
forwarded call jumps to the real `QueryBuilder` method. For a fully
type-navigable entry that works identically across IDEs, start the chain with
the new real static `Model::query()`:

```php
User::query()->with('posts')->where('active', 1)->get();
```

### 4.5 Other additions

- **QueryBuilder:** `addSelect()`, `selectRaw()`, `orderByRaw()`, `upsert()`,
  `when()`, `toSql()`, `clone()`, `aggregate()`, `prepareColumnName()`,
  `withCast()` (chainable), and `__call()` forwarding to the bound model.
- **Model:** `query()` (canonical static builder entry), `toArray()`,
  `getPrefix()`, `withCast(array $casts)`, `bool`/`boolean` cast.
- **Connection:** `startTransaction()`, `commit()`, `rollback()`.
- **Blueprint:** `unique($column = null)` — optional arg (backward compatible)
  for composite/explicit unique indexes.
- **QueryBuilder:** `static $TIME_ZONE` to set the timezone statically; `$select`
  / `$selectRaw` are now `public` (were `protected`).

---

## 5. Deprecations

| Deprecated | Replacement |
|------------|-------------|
| `QueryBuilder::startTransaction()` | `Connection::startTransaction()` |
| `QueryBuilder::commit()` | `Connection::commit()` |
| `QueryBuilder::rollback()` | `Connection::rollback()` |

Still functional, but migrate — they may be removed in a future release.

---

## 6. Migration checklist

- [ ] Replace `is_array()` / `array_*` on query results with `Collection`
      methods (§2.1).
- [ ] Remove method calls chained **after** `update()`; set `where()` before it
      (§2.2).
- [ ] Update code reading `save()`'s return as `bool`/int; it returns the model
      now (§2.3).
- [ ] Move raw expressions out of `select()` into `selectRaw()` (§2.4).
- [ ] Ensure every `delete()` has a `WHERE`; replace intentional table wipes
      with raw SQL (§2.5).
- [ ] Null-check / coalesce values from nullable cast columns (§2.6).
- [ ] Review non-SELECT `raw()` return handling (§2.7).
- [ ] Replace the old single-arg `with(Closure)` form and the old `withCount()`
      no-arg call (§2.8, §2.9).
- [ ] Replace direct `addRelation(string)` calls with `with()` (§2.10).
- [ ] Remove `binary()` column modifiers (§2.11).
- [ ] Review `catch`/message matching around the SQL-empty exception (§2.12).
- [ ] Check subclasses for name collisions with the `HasEvents` trait (§4.3).
- [ ] Migrate transaction calls from `QueryBuilder` to `Connection` (§5).
