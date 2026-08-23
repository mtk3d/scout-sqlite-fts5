# Filtering and ordering

[← back to the README](../README.md)

## Filters

Scout's `where()`, `whereIn()` and `whereNotIn()` all work. Where they are *answered* depends on whether the column is part of the index.

### Indexed filters

Declare the columns you filter on most, and they are stored in the index table itself:

```php
class Customer extends Model
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return ['name' => $this->name, 'city' => $this->city];
    }

    public function searchableFilters(): array
    {
        return ['tenant_id' => $this->tenant_id, 'status' => $this->status];
    }
}
```

```php
Customer::search('kowalski')->where('tenant_id', 1)->get();
Customer::search('kowalski')->whereIn('status', ['active', 'pending'])->get();
```

Each key becomes an `UNINDEXED` column on the virtual table — stored and filterable, but not searched. No join is needed, so this is the faster path.

Two things to know:

- **The method is also called on a bare model instance** to work out the table's columns. Return the same keys regardless of the model's state; the values may be `null`.
- **Adding a column means rebuilding.** FTS5 tables have no `ALTER TABLE`, so there is nowhere to put a new column. The driver notices the mismatch on the next write and tells you rather than failing on an insert:

```bash
php artisan scout:fts5-rebuild "App\Models\Customer"
```

### Filters on model columns

Columns you did not declare still work:

```php
Customer::search('kowalski')->where('archived_at', null)->get();
```

Because the index lives in the same SQLite file as your data, the driver joins the model's own table to answer these. It costs a join, and it is why the index has to share a connection with your models.

A filter on a column that exists in neither place throws:

```
Cannot filter search results by [statuss]: it is not an indexed filter column of
[App\Models\Customer]. Declare it in App\Models\Customer::searchableFilters() and
rebuild the index. Available columns: tenant_id, status, id, name, city, …
```

Silently ignoring an unknown filter would mean quietly returning results the caller asked you to exclude, which is worse than an exception.

### Operators

```php
Customer::search('kowalski')->where('tenant_id', '>', 3)->get();
```

Any operator SQLite understands works, on both indexed and joined columns.

## Ordering

Without an explicit order, results come back by relevance — see [ranking](how-it-works.md#ranking).

Ask for an order and you get it:

```php
Customer::search('kowalski')->latest()->get();
Customer::search('kowalski')->orderBy('name')->get();
```

Ordering columns are resolved against the model's table, so anything you can sort a query by, you can sort a search by.

## Pagination

```php
Customer::search('kowalski')->latest()->paginate(20);
```

Ordering and slicing both happen in SQL, in that order.

This matters more than it sounds. An engine that pages first and sorts afterwards is sorting one arbitrary slice per page: records show up twice, or never, and the pages do not add up to the result set. Because the order reaches the index query here, page 2 is the second page of the order you asked for, and the count reported by the paginator is every document that matched — not the size of the current page.

`simplePaginate()`, `cursor()` and `keys()` work as usual.

## Soft deletes

Turn on Scout's support:

```php
// config/scout.php
'soft_delete' => true,
```

Models using `SoftDeletes` then get a `__soft_deleted` column in their index. Trashed records stay indexed but drop out of results:

```php
Post::search('rozrząd')->get();               // only live records
Post::search('rozrząd')->withTrashed()->get();  // everything
Post::search('rozrząd')->onlyTrashed()->get();  // only trashed
```

Restoring a model brings it back into results.

Turning this on changes the index schema, so rebuild after flipping it.

## Models with string keys

UUID and ULID models work, with one difference worth knowing: their key goes in an explicit `doc_id` column instead of the table's `rowid`, and FTS5 cannot index it. Deletes and updates scan.

For most applications this is invisible. For a bulk import of hundreds of thousands of rows it is the difference between seconds and minutes — import into a freshly created index rather than overwriting an existing one, so there is nothing to delete first:

```bash
php artisan scout:fts5-rebuild "App\Models\Article"
```

[More on bulk imports →](recipes.md#bulk-imports)
