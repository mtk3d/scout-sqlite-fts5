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
use Mtk3d\Scout\Fts5\Tests\Stubs\Article;
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
class SearchTest extends TestCase
{
    public function testItMatchesAWordByPrefix(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);
        Customer::create(['name' => 'Anna Nowak']);

        $this->assertSame(['Jan Kowalski'], $this->names('kowal'));
    }

    public function testItIgnoresDiacriticsInBothDirections(): void
    {
        Customer::create(['name' => 'Łukasz Żółć', 'city' => 'Kraków']);

        $this->assertSame(['Łukasz Żółć'], $this->names('krakow'));
        $this->assertSame(['Łukasz Żółć'], $this->names('Żółć'));
        $this->assertSame(['Łukasz Żółć'], $this->names('lukasz'));
    }

    public function testItRequiresEveryWordToMatch(): void
    {
        Customer::create(['name' => 'Jan Kowalski', 'city' => 'Kraków']);
        Customer::create(['name' => 'Jan Nowak', 'city' => 'Gdańsk']);

        $this->assertSame(['Jan Kowalski'], $this->names('jan krakow'));
    }

    public function testItReportsWhichPassAnsweredTheQuery(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->assertSame('prefix', $this->raw('jan kowalski')->pass());
        $this->assertNull($this->raw('zupelnie inne slowa')->pass());
    }

    public function testItFallsBackToAShortenedPrefixWhenAWordEndsWrong(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $result = $this->raw('kowalsky');

        $this->assertSame('typo', $result->pass());
        $this->assertSame(['Jan Kowalski'], $this->names('kowalsky'));
    }

    public function testItFallsBackToAnyWordWhenNotAllOfThemMatch(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $result = $this->raw('jan brzeczyszczykiewicz');

        $this->assertSame('any', $result->pass());
        $this->assertSame(['Jan Kowalski'], $this->names('jan brzeczyszczykiewicz'));
    }

    public function testItFallsBackToSubstringsWhenAWordIsMisspelledInTheMiddle(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $result = $this->raw('kowerlski');

        $this->assertSame('trigram', $result->pass());
        $this->assertSame(['Jan Kowalski'], $this->names('kowerlski'));
    }

    public function testItRanksTheBetterMatchFirst(): void
    {
        Customer::create(['name' => 'Kowalski']);
        Customer::create([
            'name' => 'Anna Nowak',
            'city' => 'Warszawa',
            'notes' => 'przekazane przez pana Kowalskiego z serwisu obok, dawny klient warsztatu',
        ]);

        // BM25 favours the shorter document, where the term carries more weight.
        $this->assertSame(['Kowalski', 'Anna Nowak'], $this->names('kowalski'));
    }

    public function testItTreatsFts5QuerySyntaxAsLiteralText(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);
        Customer::create(['name' => 'Anna Nowak']);

        // Handed to FTS5 unquoted this would run as a boolean OR and the
        // strict pass would answer it. That it falls through to the `any`
        // pass instead shows "OR" was searched for as a word.
        $this->assertSame('any', $this->raw('kowalski OR nowak')->pass());

        // A stray quote or a function call would be a syntax error if the
        // query reached the FTS5 parser unescaped.
        $this->assertSame(['Jan Kowalski'], $this->names('"jan'));
        $this->assertSame('any', $this->raw('NEAR(jan nowak)')->pass());
    }

    public function testItReturnsNothingForAQueryWithoutWords(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->assertSame([], $this->names('   ...   '));
        $this->assertSame(0, $this->raw('')->total());
    }

    public function testItReturnsNothingWhenTheIndexDoesNotExistYet(): void
    {
        $this->assertSame([], Customer::search('kowalski')->get()->all());
    }

    public function testItSearchesModelsWithStringKeys(): void
    {
        Article::create(['title' => 'Wymiana rozrządu']);
        Article::create(['title' => 'Wymiana oleju']);

        $this->assertSame(
            ['Wymiana oleju'],
            Article::search('oleju')->get()->pluck('title')->all()
        );
    }

    public function testItHonoursAnExplicitLimit(): void
    {
        Customer::create(['name' => 'Kowalski jeden']);
        Customer::create(['name' => 'Kowalski dwa']);
        Customer::create(['name' => 'Kowalski trzy']);

        $this->assertCount(2, Customer::search('kowalski')->take(2)->get());
    }

    public function testItHandsTheRawResultToScoutsCallback(): void
    {
        // The README suggests this for telling a user the match was fuzzy.
        Customer::create(['name' => 'Jan Kowalski']);

        $pass = null;

        $customers = Customer::search('kowalsky')
            ->withRawResults(function (SearchResult $result) use (&$pass) {
                $pass = $result->pass();
            })
            ->paginate(10);

        $this->assertSame('typo', $pass);
        $this->assertCount(1, $customers);
    }

    /**
     * @return string[]
     */
    private function names(string $query): array
    {
        return Customer::search($query)->get()->pluck('name')->all();
    }

    private function raw(string $query): SearchResult
    {
        return Customer::search($query)->raw();
    }
}
