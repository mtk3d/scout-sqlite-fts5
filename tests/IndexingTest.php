<?php

declare(strict_types=1);

namespace Mtk3d\Scout\Fts5\Tests;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Mtk3d\Scout\Fts5\Exceptions\ScoutFts5Exception;
use Mtk3d\Scout\Fts5\Support\Fts5Schema;
use Mtk3d\Scout\Fts5\Tests\Stubs\Article;
use Mtk3d\Scout\Fts5\Tests\Stubs\Customer;
use PHPUnit\Framework\Attributes\Test;

class IndexingTest extends TestCase
{
    #[Test]
    public function it_creates_the_index_table_on_first_write(): void
    {
        $schema = $this->app->make(Fts5Schema::class);

        $this->assertFalse($schema->exists('customers_fts'));

        Customer::create(['name' => 'Jan Kowalski']);

        $this->assertTrue($schema->exists('customers_fts'));
    }

    #[Test]
    public function it_stores_normalized_content_and_declared_filters(): void
    {
        Customer::create(['name' => 'Łukasz Żółć', 'city' => 'Kraków', 'tenant_id' => 7]);

        $row = $this->indexRows('customers_fts')->first();

        $this->assertSame('lukasz zolc krakow', $row->content);
        $this->assertSame(7, (int) $row->tenant_id);
    }

    #[Test]
    public function it_stores_integer_keys_in_the_rowid(): void
    {
        $customer = Customer::create(['name' => 'Jan Kowalski']);

        $this->assertSame(
            (int) $customer->getKey(),
            (int) $this->connection()->table('customers_fts')->selectRaw('rowid as id')->value('id')
        );

        $this->assertNotContains('doc_id', $this->app->make(Fts5Schema::class)->columns('customers_fts'));
    }

    #[Test]
    public function it_stores_string_keys_in_a_document_column(): void
    {
        $article = Article::create(['title' => 'Hello']);

        $this->assertContains('doc_id', $this->app->make(Fts5Schema::class)->columns('articles_fts'));
        $this->assertSame($article->getKey(), $this->indexRows('articles_fts')->first()->doc_id);
    }

    #[Test]
    public function it_replaces_the_previous_document_on_update(): void
    {
        $customer = Customer::create(['name' => 'Jan Kowalski']);

        $customer->update(['name' => 'Jan Nowak']);

        $this->assertCount(1, $this->indexRows('customers_fts'));
        $this->assertSame('jan nowak', $this->indexRows('customers_fts')->first()->content);
    }

    #[Test]
    public function it_removes_documents_of_deleted_models(): void
    {
        $customer = Customer::create(['name' => 'Jan Kowalski']);
        Customer::create(['name' => 'Anna Nowak']);

        $customer->delete();

        $this->assertCount(1, $this->indexRows('customers_fts'));
    }

    #[Test]
    public function it_removes_documents_that_stop_being_searchable(): void
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

    #[Test]
    public function it_empties_the_index_without_dropping_it_on_flush(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        Customer::removeAllFromSearch();

        $this->assertTrue($this->app->make(Fts5Schema::class)->exists('customers_fts'));
        $this->assertCount(0, $this->indexRows('customers_fts'));
    }

    #[Test]
    public function it_refuses_to_index_when_auto_creation_is_disabled(): void
    {
        config()->set('scout-fts5.auto_create', false);

        $this->expectException(ScoutFts5Exception::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        Customer::create(['name' => 'Jan Kowalski']);
    }

    #[Test]
    public function it_reports_an_index_that_no_longer_matches_the_model(): void
    {
        // A table created before the model declared its filter columns cannot
        // be altered into shape: FTS5 has no ALTER TABLE.
        $this->app->make(Fts5Schema::class)->create('customers_fts');

        $this->expectException(ScoutFts5Exception::class);
        $this->expectExceptionMessageMatches('/has to be rebuilt/');

        Customer::create(['name' => 'Jan Kowalski']);
    }

    private function indexRows(string $table): Collection
    {
        return $this->connection()->table($table)->get();
    }

    private function connection(): ConnectionInterface
    {
        return $this->app->make('db')->connection();
    }
}
