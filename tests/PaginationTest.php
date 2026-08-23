<?php

declare(strict_types=1);

namespace Mtk3d\Scout\Fts5\Tests;

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
#[CoversClass(SearchResult::class)]
#[UsesClass(Fts5Engine::class)]
#[UsesClass(Fts5Indexer::class)]
#[UsesClass(Fts5ServiceProvider::class)]
#[UsesClass(SearchConfiguration::class)]
#[UsesClass(Fts5Schema::class)]
#[UsesClass(MatchQuery::class)]
#[UsesClass(SearchPass::class)]
#[UsesClass(Tokens::class)]
#[UsesClass(DiacriticsNormalizer::class)]
class PaginationTest extends TestCase
{
    public function testItReportsTheTotalBeyondTheCurrentPage(): void
    {
        $this->createCustomers(5);

        $page = Customer::search('kowalski')->paginate(2);

        $this->assertCount(2, $page->items());
        $this->assertSame(5, $page->total());
        $this->assertSame(3, $page->lastPage());
    }

    public function testItsPagesNeitherRepeatNorDropResults(): void
    {
        $this->createCustomers(5);

        $seen = [];

        foreach ([1, 2, 3] as $number) {
            $page = Customer::search('kowalski')->paginate(2, 'page', $number);

            $seen = array_merge($seen, $page->pluck('id')->all());
        }

        $this->assertCount(5, $seen);
        $this->assertSame($seen, array_unique($seen));
    }

    public function testItOrdersByAnExplicitColumnBeforeSlicingPages(): void
    {
        // The ordering has to reach the index query. Sorting only the models
        // of the current page would sort an arbitrary slice, and every page
        // would hold a different arbitrary slice.
        foreach (range(1, 5) as $number) {
            Customer::create([
                'name' => "Kowalski {$number}",
                'created_at' => now()->addDays($number),
                'updated_at' => now()->addDays($number),
            ]);
        }

        $newest = Customer::search('kowalski')->latest()->paginate(2, 'page', 1);
        $oldest = Customer::search('kowalski')->latest()->paginate(2, 'page', 3);

        $this->assertSame(['Kowalski 5', 'Kowalski 4'], $newest->pluck('name')->all());
        $this->assertSame(['Kowalski 1'], $oldest->pluck('name')->all());
    }

    public function testItKeepsRelevanceOrderWhenHydratingModels(): void
    {
        Customer::create(['name' => 'Zenon Kowalski']);
        Customer::create(['name' => 'Adam Kowalski', 'notes' => str_repeat('lorem ipsum ', 40)]);

        // Sorted by id the short document would come second; sorted by BM25 it
        // comes first, and that is the order the models must arrive in.
        $this->assertSame(
            ['Zenon Kowalski', 'Adam Kowalski'],
            Customer::search('kowalski')->get()->pluck('name')->all()
        );
    }

    public function testItPagesThroughResultsLazily(): void
    {
        $this->createCustomers(3);

        $this->assertCount(3, Customer::search('kowalski')->cursor());
    }

    private function createCustomers(int $count): void
    {
        foreach (range(1, $count) as $number) {
            Customer::create(['name' => "Kowalski {$number}"]);
        }
    }
}
