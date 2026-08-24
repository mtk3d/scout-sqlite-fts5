<?php

declare(strict_types=1);

namespace ScoutFts5\Tests;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ScoutFts5\Engine;
use ScoutFts5\Exceptions\ScoutFts5Exception;
use ScoutFts5\Indexer;
use ScoutFts5\Normalizer\DiacriticsNormalizer;
use ScoutFts5\SearchConfiguration;
use ScoutFts5\ServiceProvider;
use ScoutFts5\Support\Schema;
use ScoutFts5\Tests\Stubs\Article;
use ScoutFts5\Tests\Stubs\Customer;

#[CoversClass(Indexer::class)]
#[UsesClass(Engine::class)]
#[UsesClass(ServiceProvider::class)]
#[UsesClass(SearchConfiguration::class)]
#[UsesClass(Schema::class)]
#[UsesClass(DiacriticsNormalizer::class)]
#[UsesClass(ScoutFts5Exception::class)]
class IndexingTest extends TestCase
{
    public function testItCreatesTheIndexTableOnFirstWrite(): void
    {
        $schema = $this->app->make(Schema::class);

        $this->assertFalse($schema->exists('customers_fts'));

        Customer::create(['name' => 'Jan Kowalski']);

        $this->assertTrue($schema->exists('customers_fts'));
    }

    public function testItStoresNormalizedContentAndDeclaredFilters(): void
    {
        Customer::create(['name' => 'Łukasz Żółć', 'city' => 'Kraków', 'tenant_id' => 7]);

        $row = $this->indexRows('customers_fts')->first();

        $this->assertSame('lukasz zolc krakow', $row->content);
        $this->assertSame(7, (int) $row->tenant_id);
    }

    public function testItStoresIntegerKeysInTheRowid(): void
    {
        $customer = Customer::create(['name' => 'Jan Kowalski']);

        $this->assertSame(
            (int) $customer->getKey(),
            (int) $this->connection()->table('customers_fts')->selectRaw('rowid as id')->value('id')
        );

        $this->assertNotContains('doc_id', $this->app->make(Schema::class)->columns('customers_fts'));
    }

    public function testItStoresStringKeysInADocumentColumn(): void
    {
        $article = Article::create(['title' => 'Hello']);

        $this->assertContains('doc_id', $this->app->make(Schema::class)->columns('articles_fts'));
        $this->assertSame($article->getKey(), $this->indexRows('articles_fts')->first()->doc_id);
    }

    public function testItReplacesThePreviousDocumentOnUpdate(): void
    {
        $customer = Customer::create(['name' => 'Jan Kowalski']);

        $customer->update(['name' => 'Jan Nowak']);

        $this->assertCount(1, $this->indexRows('customers_fts'));
        $this->assertSame('jan nowak', $this->indexRows('customers_fts')->first()->content);
    }

    public function testItRemovesDocumentsOfDeletedModels(): void
    {
        $customer = Customer::create(['name' => 'Jan Kowalski']);
        Customer::create(['name' => 'Anna Nowak']);

        $customer->delete();

        $this->assertCount(1, $this->indexRows('customers_fts'));
    }

    public function testItRemovesDocumentsThatStopBeingSearchable(): void
    {
        // Scout reads an empty searchable array as "keep this out of results".
        $customer = Customer::create(['name' => 'Jan Kowalski']);

        Customer::saved(fn () => null);

        $this->assertCount(1, $this->indexRows('customers_fts'));

        $empty = new class extends Customer
        {
            protected $table = 'customers';

            public function searchableAs(): string
            {
                return 'customers';
            }

            public function toSearchableArray(): array
            {
                return [];
            }
        };

        $empty->forceFill($customer->getAttributes())->exists = true;
        $empty->searchable();

        $this->assertCount(0, $this->indexRows('customers_fts'));
    }

    public function testItEmptiesTheIndexWithoutDroppingItOnFlush(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        Customer::removeAllFromSearch();

        $this->assertTrue($this->app->make(Schema::class)->exists('customers_fts'));
        $this->assertCount(0, $this->indexRows('customers_fts'));
    }

    public function testItRefusesToIndexWhenAutoCreationIsDisabled(): void
    {
        config()->set('scout-fts5.auto_create', false);

        $this->expectException(ScoutFts5Exception::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        Customer::create(['name' => 'Jan Kowalski']);
    }

    public function testItReportsAnIndexThatNoLongerMatchesTheModel(): void
    {
        // A table created before the model declared its filter columns cannot
        // be altered into shape: FTS5 has no ALTER TABLE.
        $this->app->make(Schema::class)->create('customers_fts');

        $this->expectException(ScoutFts5Exception::class);
        $this->expectExceptionMessageMatches('/has to be rebuilt/');

        Customer::create(['name' => 'Jan Kowalski']);
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function indexRows(string $table): Collection
    {
        return $this->connection()->table($table)->get();
    }

    private function connection(): ConnectionInterface
    {
        return $this->app->make('db')->connection();
    }
}
