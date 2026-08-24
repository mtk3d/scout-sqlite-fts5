<?php

declare(strict_types=1);

namespace ScoutFts5\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ScoutFts5\Engine;
use ScoutFts5\Indexer;
use ScoutFts5\Normalizer\DiacriticsNormalizer;
use ScoutFts5\SearchConfiguration;
use ScoutFts5\SearchResult;
use ScoutFts5\Seeker;
use ScoutFts5\ServiceProvider;
use ScoutFts5\Support\MatchQuery;
use ScoutFts5\Support\Schema;
use ScoutFts5\Support\SearchPass;
use ScoutFts5\Support\Tokens;
use ScoutFts5\Tests\Stubs\Customer;

/**
 * The query builder applies the connection's table prefix on its own, while
 * every hand-written SQL fragment has to apply it itself. When the two
 * disagree, nothing works — so this runs the whole path with a prefix set.
 */
#[CoversClass(Schema::class)]
#[UsesClass(Engine::class)]
#[UsesClass(Indexer::class)]
#[UsesClass(Seeker::class)]
#[UsesClass(ServiceProvider::class)]
#[UsesClass(SearchConfiguration::class)]
#[UsesClass(SearchResult::class)]
#[UsesClass(MatchQuery::class)]
#[UsesClass(SearchPass::class)]
#[UsesClass(Tokens::class)]
#[UsesClass(DiacriticsNormalizer::class)]
class TablePrefixTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.testing.prefix', 'app_');
    }

    public function testItCreatesTheIndexUnderThePrefixedName(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->assertTrue($this->app->make(Schema::class)->exists('customers_fts'));

        $tables = $this->app->make('db')->connection()->select(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '%customers_fts'"
        );

        $this->assertSame(['app_customers_fts'], array_map(fn ($row) => $row->name, $tables));
    }

    public function testItSearchesRanksAndFiltersThroughThePrefix(): void
    {
        Customer::create(['name' => 'Jan Kowalski', 'tenant_id' => 1]);
        Customer::create(['name' => 'Adam Kowalski', 'tenant_id' => 2, 'notes' => str_repeat('lorem ipsum ', 40)]);

        $this->assertSame(
            ['Jan Kowalski', 'Adam Kowalski'],
            Customer::search('kowalski')->get()->pluck('name')->all()
        );

        $this->assertSame(
            ['Adam Kowalski'],
            Customer::search('kowalski')->where('tenant_id', 2)->get()->pluck('name')->all()
        );

        $this->assertSame(
            ['Adam Kowalski'],
            Customer::search('kowalski')->where('status', 'active')->latest('id')->take(1)->get()->pluck('name')->all()
        );
    }

    public function testItFallsBackThroughThePrefixToo(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->assertSame('typo', Customer::search('kowalsky')->raw()->pass());
        $this->assertSame('trigram', Customer::search('kowerlski')->raw()->pass());
    }

    public function testItFlushesThroughThePrefix(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->assertCount(1, Customer::search('kowalski')->get());

        $this->artisan('scout:flush', ['model' => Customer::class])->assertSuccessful();

        $this->assertCount(0, Customer::search('kowalski')->get());
        $this->assertTrue($this->app->make(Schema::class)->exists('customers_fts'));
    }

    public function testItsCommandsWorkThroughThePrefix(): void
    {
        Customer::withoutSyncingToSearch(fn () => Customer::create(['name' => 'Jan Kowalski']));

        $this->artisan('scout:fts5-rebuild', ['model' => [Customer::class]])->assertSuccessful();

        $this->assertCount(1, Customer::search('kowalski')->get());

        $this->artisan('scout:fts5-drop', ['model' => [Customer::class]])->assertSuccessful();

        $this->assertFalse($this->app->make(Schema::class)->exists('customers_fts'));
    }
}
