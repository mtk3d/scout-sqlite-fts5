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

#[CoversClass(Seeker::class)]
#[UsesClass(Engine::class)]
#[UsesClass(Indexer::class)]
#[UsesClass(ServiceProvider::class)]
#[UsesClass(SearchConfiguration::class)]
#[UsesClass(SearchResult::class)]
#[UsesClass(Schema::class)]
#[UsesClass(MatchQuery::class)]
#[UsesClass(SearchPass::class)]
#[UsesClass(Tokens::class)]
#[UsesClass(DiacriticsNormalizer::class)]
#[UsesClass(ScoutFts5Exception::class)]
class FilterTest extends TestCase
{
    public function testItFiltersOnAColumnStoredInTheIndex(): void
    {
        Customer::create(['name' => 'Jan Kowalski', 'tenant_id' => 1]);
        Customer::create(['name' => 'Adam Kowalski', 'tenant_id' => 2]);

        $this->assertSame(
            ['Adam Kowalski'],
            Customer::search('kowalski')->where('tenant_id', 2)->get()->pluck('name')->all()
        );
    }

    public function testItFiltersOnAColumnThatOnlyExistsOnTheModel(): void
    {
        // `status` is not part of the index. Since the index lives in the same
        // SQLite database as the models, the filter is answered by joining
        // the model's own table rather than being ignored.
        Customer::create(['name' => 'Jan Kowalski', 'status' => 'active']);
        Customer::create(['name' => 'Adam Kowalski', 'status' => 'archived']);

        $this->assertSame(
            ['Jan Kowalski'],
            Customer::search('kowalski')->where('status', 'active')->get()->pluck('name')->all()
        );
    }

    public function testItSupportsComparisonOperators(): void
    {
        Customer::create(['name' => 'Jan Kowalski', 'tenant_id' => 1]);
        Customer::create(['name' => 'Adam Kowalski', 'tenant_id' => 5]);

        $this->assertSame(
            ['Adam Kowalski'],
            Customer::search('kowalski')->where('tenant_id', '>', 3)->get()->pluck('name')->all()
        );
    }

    public function testItSupportsWhereInAndWhereNotIn(): void
    {
        Customer::create(['name' => 'Jan Kowalski', 'tenant_id' => 1]);
        Customer::create(['name' => 'Adam Kowalski', 'tenant_id' => 2]);
        Customer::create(['name' => 'Ewa Kowalski', 'tenant_id' => 3]);

        $this->assertSame(
            ['Jan Kowalski', 'Adam Kowalski'],
            Customer::search('kowalski')->whereIn('tenant_id', [1, 2])->get()->pluck('name')->all()
        );

        $this->assertSame(
            ['Ewa Kowalski'],
            Customer::search('kowalski')->whereNotIn('tenant_id', [1, 2])->get()->pluck('name')->all()
        );
    }

    public function testItCountsOnlyTheDocumentsThatPassTheFilter(): void
    {
        foreach (range(1, 6) as $number) {
            Customer::create(['name' => "Kowalski {$number}", 'tenant_id' => $number <= 2 ? 1 : 2]);
        }

        $page = Customer::search('kowalski')->where('tenant_id', 1)->paginate(5);

        $this->assertSame(2, $page->total());
    }

    public function testItAppliesFiltersToTheFallbackPassesToo(): void
    {
        Customer::create(['name' => 'Jan Kowalski', 'tenant_id' => 1]);
        Customer::create(['name' => 'Adam Kowalski', 'tenant_id' => 2]);

        $result = Customer::search('kowalsky')->where('tenant_id', 2)->raw();

        $this->assertSame('typo', $result->pass());
        $this->assertSame(1, $result->total());
    }

    public function testItRejectsAFilterOnAnUnknownField(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->expectException(ScoutFts5Exception::class);
        $this->expectExceptionMessageMatches('/not an indexed filter column/');

        Customer::search('kowalski')->where('nie_ma_takiej_kolumny', 1)->get();
    }
}
