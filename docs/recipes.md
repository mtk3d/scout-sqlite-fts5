# Recipes

[← back to the README](https://github.com/mtk3d/scout-sqlite-fts5#readme)

## A search box

The whole point of the cascade is that a search box can be forgiving without you writing any of the forgiveness. A Livewire component needs nothing special:

```php
class CustomerList extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $customers = $this->search === ''
            ? Customer::query()->latest()->paginate(20)
            : Customer::search($this->search)->paginate(20);

        return view('livewire.customers.list', compact('customers'));
    }
}
```

Note what is *not* there: no `->latest()` on the search branch. Sorting a search by date throws away the ranking — see [ordering](filtering-and-ordering.md#ordering).

## Telling the user you guessed

The result object reports which pass answered, so you can say so instead of silently returning something the user did not ask for. Scout's `withRawResults()` hands it to you without running the search twice:

```php
$approximate = false;

$customers = Customer::search($this->search)
    ->withRawResults(function ($result) use (&$approximate) {
        $approximate = $result->pass() !== null && $result->pass() !== 'prefix';
    })
    ->paginate(20);
```

```blade
@if ($approximate)
    <p>No exact match for <strong>{{ $term }}</strong> — showing the closest results.</p>
@endif
```

In practice `prefix` means "found it", and anything else means "found something like it". `withRawResults()` runs on the paginating methods; outside them, `raw()` returns the same object.

## Indexing relations

`toSearchableArray()` is just an array. Anything you can load, you can index:

```php
public function toSearchableArray(): array
{
    return [
        'name' => $this->name,
        'phone' => $this->phone,
        'vehicles' => $this->vehicles
            ->map(fn ($v) => "{$v->make} {$v->model} {$v->registration}")
            ->implode(' '),
    ];
}
```

Now a registration number finds its owner. Two things to keep in mind:

**Eager load during imports**, or `scout:import` will run a query per model:

```php
public function makeAllSearchableUsing(Builder $query): Builder
{
    return $query->with('vehicles');
}
```

**Reindex the parent when the relation changes** — Scout only watches the model that owns the index:

```php
class Vehicle extends Model
{
    protected static function booted(): void
    {
        static::saved(fn (Vehicle $vehicle) => $vehicle->customer?->searchable());
        static::deleted(fn (Vehicle $vehicle) => $vehicle->customer?->searchable());
    }
}
```

## Keeping records out of the index

Scout's `shouldBeSearchable()` decides per record:

```php
public function shouldBeSearchable(): bool
{
    return $this->status !== 'draft';
}
```

Records that stop qualifying are removed from the index on their next save, so a draft disappears from search without being deleted.

## Multi-tenancy

Declare the tenant key as a filter so it never leaves the index:

```php
public function searchableFilters(): array
{
    return ['tenant_id' => $this->tenant_id];
}
```

Then apply it everywhere, in one place rather than at every call site. Scout's builder is macroable:

```php
// in a service provider
use Laravel\Scout\Builder;

Builder::macro('forTenant', function () {
    return $this->where('tenant_id', auth()->user()->tenant_id);
});
```

```php
Customer::search($term)->forTenant()->paginate(20);
```

Filtering inside the index means the tenant check happens before ranking and pagination, so counts and pages are right — and a cross-tenant record never reaches PHP.

If you prefer one index per tenant, override `searchableAs()` instead and let each tenant have its own virtual table. Turn `auto_create` on so new tenants get theirs on first write.

## Bulk imports

Importing into an existing index makes every row delete whatever was there before. Rebuilding skips that entirely:

```bash
php artisan scout:fts5-rebuild "App\Models\Customer"
```

That drops the table, recreates it, runs `scout:import` and optimizes. For a large import, also widen Scout's chunk and take the work off the request:

```php
// config/scout.php
'chunk' => ['searchable' => 2000],
```

Rebuilding is destructive while it runs: the index is empty between the drop and the end of the import, so searches during that window find nothing. On a live application, import into a differently suffixed index and switch `scout-fts5.suffix` when it finishes.

After any large import, merge the index segments:

```bash
php artisan scout:fts5-optimize
```

It rewrites the index into as few segments as possible. Worth doing after tens of thousands of writes; pointless after a handful.

## NativePHP and other shipped databases

Desktop builds have no migration step and no deploy, which suits an index derived from models rather than migrations. Leave `auto_create` on and the tables appear on first write.

If your build rewrites the database path at runtime, point the application at it before rebuilding:

```php
#[AsCommand(name: 'native:search:rebuild')]
class NativeSearchRebuild extends Command
{
    public function handle(): int
    {
        (new NativeServiceProvider($this->laravel))->rewriteDatabase();

        return $this->call('scout:fts5-rebuild');
    }
}
```

The exact API depends on your NativePHP version; the point is that the rebuild has to run against the same database file the app will open.

## Testing search in your application

The index is an ordinary part of the database, so it behaves under `RefreshDatabase` like everything else — with one wrinkle: `RefreshDatabase` rolls back a transaction, and a virtual table created inside that transaction goes with it. With `auto_create` on, the table is simply recreated on the next write, so tests pass either way.

To assert on ranking rather than membership, compare the whole ordered list:

```php
public function test_an_exact_name_outranks_a_mention_in_notes(): void
{
    $exact = Customer::create(['name' => 'Kowalski']);
    $mention = Customer::create(['name' => 'Nowak', 'notes' => 'polecony przez Kowalskiego']);

    $this->assertSame(
        [$exact->id, $mention->id],
        Customer::search('kowalski')->keys()->all()
    );
}
```

To keep a factory out of the index, wrap it:

```php
Customer::withoutSyncingToSearch(fn () => Customer::factory()->count(1000)->create());
```
