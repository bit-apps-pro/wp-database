# wp-database

A small ActiveRecord ORM with a fluent query and schema builder on top of
WordPress' `$wpdb`.

## Install

```jsonc
// composer.json
"repositories": [
  { "type": "vcs", "url": "https://github.com/Bit-Apps-Pro/wp-database" }
]
```

```bash
composer require bitapps/wp-database:dev-main
```

## Quick start

```php
use BitApps\WPDatabase\Model;

class Contact extends Model
{
    protected $fillable = ['name', 'email'];

    public function deals()
    {
        return $this->hasMany(Deal::class, 'contact_id', 'id');
    }
}

Contact::insert(['name' => 'Ada', 'email' => 'ada@x.com']);

$active = Contact::where('is_active', 1)
    ->withCount('deals')
    ->orderBy('name')->asc()
    ->get();                       // Collection of Contact models
```

## Documentation

- **[Usage guide](docs/usage.md)** — models, query builder, relationships,
  casts, events, transactions, and more.
- **[Schema builder](docs/schema.md)** — table creation, columns, indexes, and migrations.
- **[Breaking changes](docs/breaking-changes.md)** — upgrade notes.
