### WPKit/Database

---

# usage

1. Add this repository in composer.json

```
"repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/Bit-Apps-Pro/wp-database"
    }
  ]
```

2. Then install the package

```
composer require bitapps/wp-database:^1.12
```

## SQL structure safety

Structured query methods accept identifiers, operators, join types, and numeric
limits only. Invalid structure throws before a query is executed. Dynamic values
belong in bindings; do not concatenate request data into SQL.

Use `rawPrepared()` for reviewed static templates that need dynamic identifiers
or sort directions:

```php
$query->rawPrepared(
    'SELECT {{identifier:column}} FROM {{identifier:table}}'
        . ' ORDER BY {{identifier:column}} {{direction:sort}}',
    [],
    ['column' => 'contacts.id', 'table' => 'contacts'],
    ['sort' => 'DESC']
);
```

Only unnumbered `%s`, `%d`, `%f`, and `%F` value placeholders are accepted;
write `%%` for a literal percent. `unsafeRaw()` is the explicit legacy escape
hatch for fully developer-controlled SQL. `raw()` remains as a deprecated alias
of that unsafe path. Request interpolation into either method is unsupported.

Joins compare columns by default. Use `joinWhere()` / `onValue()` for a bound
right-hand value, or `onRaw()` for a reviewed developer-authored expression.
