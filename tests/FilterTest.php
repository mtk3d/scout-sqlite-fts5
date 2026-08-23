<?php

declare(strict_types=1);

namespace Mtk3d\Scout\Fts5\Tests;

use Mtk3d\Scout\Fts5\Exceptions\ScoutFts5Exception;
use Mtk3d\Scout\Fts5\Fts5Engine;
use Mtk3d\Scout\Fts5\Fts5Indexer;
use Mtk3d\Scout\Fts5\Fts5Seeker;
use Mtk3d\Scout\Fts5\Fts5ServiceProvider;
use Mtk3d\Scout\Fts5\Normalizer\DiacriticsNormalizer;
use Mtk3d\Scout\Fts5\SearchConfiguration;
use Mtk3d\Scout\Fts5\SearchResult;
use Mtk3d\Scout\Fts5\Support\Fts5Schema;
use Mtk3d\Scout\Fts5\Support\MatchQuery;
use Mtk3d\Scout\Fts5\Support\SearchPass;
use Mtk3d\Scout\Fts5\Support\Tokens;
use Mtk3d\Scout\Fts5\Tests\Stubs\Customer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(Fts5Seeker::class)]
#[UsesClass(Fts5Engine::class)]
#[UsesClass(Fts5Indexer::class)]
#[UsesClass(Fts5ServiceProvider::class)]
#[UsesClass(SearchConfiguration::class)]
#[UsesClass(SearchResult::class)]
#[UsesClass(Fts5Schema::class)]
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
