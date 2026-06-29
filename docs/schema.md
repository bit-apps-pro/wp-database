# Schema Builder Reference

Use `Schema` (a thin facade over `Blueprint`) to create, alter and drop MySQL tables from
PHP. The table prefix (WordPress prefix + plugin prefix) is applied automatically — pass
bare table names everywhere.

See [Defining models](usage.md#defining-models) for the model-side `$timestamps` and
`$soft_deletes` properties that rely on the columns described here.

---

- [Creating tables](#creating-tables)
- [Column types](#column-types)
  - [String / binary](#string--binary)
  - [Text / blob](#text--blob)
  - [Numeric](#numeric)
  - [Enumerated](#enumerated)
  - [Date / time](#date--time)
- [Column modifiers](#column-modifiers)
- [Column helpers](#column-helpers)
  - [id()](#id)
  - [increments()](#increments)
  - [string()](#string)
  - [timestamps()](#timestamps)
  - [softDeletes()](#softdeletes)
- [Foreign keys](#foreign-keys)
- [Altering & dropping](#altering--dropping)
- [Table operations](#table-operations)

---

## Creating tables

```php
use BitApps\WPDatabase\Schema;

Schema::create('orders', function ($table) {
    $table->id();
    $table->bigint('user_id')->unsigned();
    $table->varchar('status', 32)->defaultValue('pending');
    $table->decimal('total')->nullable();
    $table->timestamps();

    $table->bigint('user_id')->unsigned()
        ->foreign('users', 'id')
        ->onDelete()->cascade();
});
```

The callback receives a `Blueprint` instance. Column-definition methods return the same
`Blueprint`, so modifiers chain directly:

```php
$table->varchar('slug', 100)->nullable()->unique();
```

---

## Column types

All types listed below are registered in `Blueprint::isValidType()` and exposed as magic
methods via `Blueprint::__call()`. The first argument is always the column name; the
optional second argument sets the length (or, for `ENUM`/`SET`, the list of allowed values
as an array).

### String / binary

| Method | SQL type | Notes |
|---|---|---|
| `char($name, $length = null)` | `CHAR` | |
| `varchar($name, $length = null)` | `VARCHAR` | |
| `binary($name, $length = null)` | `BINARY` | Column **type** — see note |
| `varbinary($name, $length = null)` | `VARBINARY` | Column **type** — see note |
| `json($name, $length = null)` | `JSON` | |

> **`binary` / `varbinary` are column types, not modifiers.** These define columns
> with the `BINARY` / `VARBINARY` SQL type. A chained `binary()` *modifier* (which
> would have flagged an existing column as binary) was removed in an earlier refactor;
> only the type declarations remain. Use `$table->binary('data', 16)` to define a
> `BINARY(16)` column.

### Text / blob

| Method | SQL type |
|---|---|
| `tinytext($name)` | `TINYTEXT` |
| `text($name)` | `TEXT` |
| `mediumtext($name)` | `MEDIUMTEXT` |
| `longtext($name)` | `LONGTEXT` |
| `tinyblob($name)` | `TINYBLOB` |
| `blob($name)` | `BLOB` |
| `mediumblob($name)` | `MEDIUMBLOB` |
| `longblob($name)` | `LONGBLOB` |

### Numeric

| Method | SQL type | Notes |
|---|---|---|
| `bit($name, $length = null)` | `BIT` | |
| `tinyint($name, $length = null)` | `TINYINT` | |
| `bool($name)` | `BOOL` | |
| `boolean($name)` | `BOOLEAN` | Alias of `bool` |
| `smallint($name, $length = null)` | `SMALLINT` | |
| `mediumint($name, $length = null)` | `MEDIUMINT` | |
| `int($name, $length = null)` | `INT` | |
| `integer($name, $length = null)` | `INTEGER` | Alias of `int` |
| `bigint($name, $length = null)` | `BIGINT` | |
| `float($name, $length = null)` | `FLOAT` | |
| `double($name, $length = null)` | `DOUBLE` | |
| `double_precision($name, $length = null)` | `DOUBLE PRECISION` | Underscore maps to space |
| `decimal($name, $length = null)` | `DECIMAL` | |
| `dec($name, $length = null)` | `DEC` | Alias of `decimal` |

### Enumerated

| Method | SQL type | Notes |
|---|---|---|
| `enum($name, array $enum)` | `ENUM` | Second arg is an array of allowed string values |
| `set($name, array $set)` | `SET` | Second arg is an array of allowed string values |

```php
$table->enum('status', ['draft', 'published', 'archived']);
$table->set('permissions', ['read', 'write', 'delete']);
```

### Date / time

| Method | SQL type |
|---|---|
| `date($name)` | `DATE` |
| `datetime($name)` | `DATETIME` |
| `timestamp($name)` | `TIMESTAMP` |
| `time($name)` | `TIME` |
| `year($name)` | `YEAR` |

---

## Column modifiers

Chain any of these immediately after a type call. Each returns the `Blueprint` so multiple
modifiers can be stacked.

| Modifier | Effect |
|---|---|
| `nullable()` | Emits `NULL` instead of `NOT NULL`. |
| `defaultValue($value)` | Adds `DEFAULT $value`. Integers are emitted unquoted; `CURRENT_TIMESTAMP` and `NULL` are also unquoted; all other strings are single-quoted automatically. |
| `unsigned()` | Adds `UNSIGNED` (meaningful on numeric columns). |
| `zeroFill()` | Adds `ZEROFILL`. |
| `primary()` | Registers the column in the `PRIMARY KEY` clause and forces `NOT NULL`. |
| `unique($column = null)` | Registers a `UNIQUE INDEX`. Without an argument it indexes the current column. Pass an explicit column name or an array of column names to create a named or composite unique index. |
| `index($type = null)` | Registers an `INDEX` on the current column. Pass an index-type prefix string (e.g. `'FULLTEXT '`) to customise the index clause. |
| `length($length)` | Sets the column length. Accepts an array for `ENUM`/`SET` allowed values — each element is single-quoted and the list is comma-joined automatically. |

---

## Column helpers

Higher-level helpers that expand to one or more fully configured column definitions.

### id()

```php
$table->id();
```

Defines `id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` and registers it as the `PRIMARY KEY`.
Shorthand for:

```php
$table->bigint('id')->unsigned()->increments()->primary();
```

### increments()

```php
$table->bigint('sort_order')->increments();
// or pass a name to create the column and apply AUTO_INCREMENT in one call:
$table->increments('sort_order');   // creates BIGINT sort_order NOT NULL AUTO_INCREMENT
```

Adds `AUTO_INCREMENT` to the most recently defined column. When called with a column name
argument it first creates a `BIGINT` column with that name, then applies `AUTO_INCREMENT`.

### string()

```php
$table->string('email');
$table->string('slug')->unique();
```

Defines a `VARCHAR(255)` column. Equivalent to `$table->varchar($name)->length(255)`.

### timestamps()

```php
$table->timestamps();
```

Adds two nullable `TIMESTAMP` columns — `created_at` and `updated_at` — both defaulting
to `NULL`.

The model-side `$timestamps = true` property (see
[Defining models](usage.md#defining-models)) instructs the ORM to auto-populate these
columns on every insert and update. The table must contain these two columns for
`$timestamps` to function.

### softDeletes()

```php
$table->softDeletes();
```

Adds a single nullable `TIMESTAMP` column named `deleted_at`, defaulting to `NULL`.

The model-side `$soft_deletes = true` property (see
[Defining models](usage.md#defining-models)) instructs `delete()` to set `deleted_at`
rather than removing the row. **Reads are not filtered** — soft-deleted rows are returned
by `all()` and every query; append `->whereNull('deleted_at')` manually to exclude them.

---

## Foreign keys

Foreign key constraints are declared by chaining on the column that holds the FK value:

```php
$table->bigint('user_id')->unsigned()
    ->foreign('users', 'id')       // references users(id)
    ->onDelete()->cascade()        // ON DELETE CASCADE
    ->onUpdate()->restrict();      // ON UPDATE RESTRICT
```

| Method | Description |
|---|---|
| `foreign($ref, $refCol)` | Declares a `FOREIGN KEY` from the current column to column `$refCol` on table `$ref`. The prefix is applied to `$ref` automatically. |
| `onDelete()` | Selects the `ON DELETE` slot for the next action modifier. |
| `onUpdate()` | Selects the `ON UPDATE` slot for the next action modifier. |
| `cascade()` | Applies `CASCADE`. If `onDelete()` or `onUpdate()` was called first, only that slot is set; otherwise both `ON DELETE` and `ON UPDATE` are set to `CASCADE`. |
| `restrict()` | Applies `RESTRICT`. Same scoping rules as `cascade()`. |
| `setNull()` | Applies `SET NULL`. Same scoping rules as `cascade()`. |

---

## Altering & dropping

Use `Schema::edit` to alter an existing table. Inside the callback, call the same column
definitions as in `Schema::create` to add columns, and use the drop/rename helpers to
modify or remove existing ones.

```php
Schema::edit('orders', function ($table) {
    $table->varchar('reference', 64)->nullable();   // ADD COLUMN
    $table->tinyint('priority')->defaultValue(0);   // ADD COLUMN
    $table->dropColumn('legacy_notes');             // DROP COLUMN
    $table->dropTimestamps();                       // DROP created_at + updated_at
});
```

### Modifying an existing column

Chain `change()` on a column definition inside `Schema::edit` to emit `CHANGE COLUMN`
instead of `ADD COLUMN`:

```php
Schema::edit('orders', function ($table) {
    $table->varchar('reference', 128)->change();    // CHANGE COLUMN — widen the length
});
```

### Drop helpers

The following can be called inside a `Schema::edit` callback or as a direct static call
(e.g. `Schema::dropColumn('orders', 'legacy_notes')`).

| Method | Emitted SQL |
|---|---|
| `dropColumn($column)` | `DROP $column` |
| `dropTimestamps()` | `DROP COLUMN created_at, DROP COLUMN updated_at` |
| `dropIndex($indexes)` | `DROP INDEX \`name\`` — accepts a single name string or an array of names |
| `dropUnique($indexes)` | Alias of `dropIndex` — unique indexes are dropped the same way as regular indexes, by name |
| `dropForeign($keys)` | `DROP FOREIGN KEY \`name\`` — accepts a single key name string or an array |
| `dropPrimary()` | `DROP PRIMARY KEY` |

### renameColumn()

```php
// Direct call — renames a single column on the table
Schema::renameColumn('orders', 'fname', 'first_name');
// Emits: ALTER TABLE wp_orders CHANGE fname first_name
```

Renames a column via MySQL's `CHANGE` clause. Note that this implementation emits
`CHANGE old_name new_name` without repeating the column definition, which is accepted
by MySQL 8.0+ (which supports bare `RENAME COLUMN`) but may require the full type
specification on older MySQL / MariaDB versions.

---

## Table operations

All methods are available as static calls (`Schema::create(...)`) and as instance calls
on `(new Schema())->create(...)` — both are equivalent thanks to `__callStatic`.

| Method | Description |
|---|---|
| `Schema::create($table, Closure $callback)` | `CREATE TABLE IF NOT EXISTS`. The callback receives the `Blueprint`. |
| `Schema::edit($table, Closure $callback)` | `ALTER TABLE`. Use to add columns, drop columns, add/drop indexes and foreign keys. |
| `Schema::drop($table)` | `DROP TABLE IF EXISTS`. |
| `Schema::rename($table, $newName)` | `ALTER TABLE … RENAME TO`. The prefix is applied to both names. |
| `Schema::withPrefix($prefix)` | Overrides the automatic prefix for this call. Returns a `Schema` instance; chain `.create()`, `.edit()`, etc. |

```php
// Override prefix — table resolves as "custom_orders", not "wp_plugin_orders"
Schema::withPrefix('custom_')->create('orders', function ($table) {
    $table->id();
    $table->string('reference');
    $table->timestamps();
});
```
