<?php

declare(strict_types=1);

namespace ScoutFts5\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ScoutFts5\Engine;
use ScoutFts5\Exceptions\ScoutFts5Exception;
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
 * The index can live in a database of its own — which keeps it out of backups
 * and lets it be deleted and rebuilt without touching application data — at
 * the price of the queries that need the model's own table.
 */
#[CoversClass(Seeker::class)]
#[CoversClass(Schema::class)]
#[UsesClass(Engine::class)]
#[UsesClass(Indexer::class)]
#[UsesClass(ServiceProvider::class)]
#[UsesClass(SearchConfiguration::class)]
#[UsesClass(SearchResult::class)]
#[UsesClass(MatchQuery::class)]
#[UsesClass(SearchPass::class)]
#[UsesClass(Tokens::class)]
#[UsesClass(DiacriticsNormalizer::class)]
class SeparateIndexDatabaseTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.searchdb', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('scout-fts5.connection', 'searchdb');
    }

    public function testItKeepsTheIndexOutOfTheApplicationDatabase(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->assertNotEmpty($this->indexTablesOn('searchdb'));
        $this->assertEmpty($this->indexTablesOn('testing'));
    }

    public function testSearchingAndIndexedFiltersStillWork(): void
    {
        Customer::create(['name' => 'Jan Kowalski', 'tenant_id' => 1]);
        Customer::create(['name' => 'Adam Kowalski', 'tenant_id' => 2]);

        $this->assertCount(2, Customer::search('kowalski')->get());
        $this->assertSame(
            ['Adam Kowalski'],
            Customer::search('kowalski')->where('tenant_id', 2)->get()->pluck('name')->all()
        );
    }

    public function testOrderingByAModelColumnExplainsWhyItCannotWork(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->expectException(ScoutFts5Exception::class);
        $this->expectExceptionMessageMatches('/cannot join across connections/');

        Customer::search('kowalski')->latest()->get();
    }

    public function testFilteringOnAModelColumnExplainsWhyItCannotWork(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->expectException(ScoutFts5Exception::class);
        $this->expectExceptionMessageMatches('/cannot join across connections/');

        Customer::search('kowalski')->where('status', 'active')->get();
    }

    /**
     * @return string[]
     */
    private function indexTablesOn(string $connection): array
    {
        $rows = $this->app->make('db')->connection($connection)->select(
            "SELECT name FROM sqlite_master WHERE name LIKE '%\_fts' ESCAPE '\'"
        );

        return array_map(fn ($row) => $row->name, $rows);
    }
}
