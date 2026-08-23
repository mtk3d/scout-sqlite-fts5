<?php

declare(strict_types=1);

namespace Mtk3d\Scout\Fts5\Tests;

use Mtk3d\Scout\Fts5\SearchResult;
use Mtk3d\Scout\Fts5\Tests\Stubs\Article;
use Mtk3d\Scout\Fts5\Tests\Stubs\Customer;
use PHPUnit\Framework\Attributes\Test;

class SearchTest extends TestCase
{
    #[Test]
    public function it_matches_a_word_by_prefix(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);
        Customer::create(['name' => 'Anna Nowak']);

        $this->assertSame(['Jan Kowalski'], $this->names('kowal'));
    }

    #[Test]
    public function it_ignores_diacritics_in_both_directions(): void
    {
        Customer::create(['name' => 'Łukasz Żółć', 'city' => 'Kraków']);

        $this->assertSame(['Łukasz Żółć'], $this->names('krakow'));
        $this->assertSame(['Łukasz Żółć'], $this->names('Żółć'));
        $this->assertSame(['Łukasz Żółć'], $this->names('lukasz'));
    }

    #[Test]
    public function it_requires_every_word_to_match(): void
    {
        Customer::create(['name' => 'Jan Kowalski', 'city' => 'Kraków']);
        Customer::create(['name' => 'Jan Nowak', 'city' => 'Gdańsk']);

        $this->assertSame(['Jan Kowalski'], $this->names('jan krakow'));
    }

    #[Test]
    public function it_reports_which_pass_answered_the_query(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->assertSame('prefix', $this->raw('jan kowalski')->pass());
        $this->assertNull($this->raw('zupelnie inne slowa')->pass());
    }

    #[Test]
    public function it_falls_back_to_a_shortened_prefix_when_a_word_ends_wrong(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $result = $this->raw('kowalsky');

        $this->assertSame('typo', $result->pass());
        $this->assertSame(['Jan Kowalski'], $this->names('kowalsky'));
    }

    #[Test]
    public function it_falls_back_to_any_word_when_not_all_of_them_match(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $result = $this->raw('jan brzeczyszczykiewicz');

        $this->assertSame('any', $result->pass());
        $this->assertSame(['Jan Kowalski'], $this->names('jan brzeczyszczykiewicz'));
    }

    #[Test]
    public function it_falls_back_to_substrings_when_a_word_is_misspelled_in_the_middle(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $result = $this->raw('kowerlski');

        $this->assertSame('trigram', $result->pass());
        $this->assertSame(['Jan Kowalski'], $this->names('kowerlski'));
    }

    #[Test]
    public function it_ranks_the_better_match_first(): void
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

    #[Test]
    public function it_treats_fts5_query_syntax_as_literal_text(): void
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

    #[Test]
    public function it_returns_nothing_for_a_query_without_words(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->assertSame([], $this->names('   ...   '));
        $this->assertSame(0, $this->raw('')->total());
    }

    #[Test]
    public function it_returns_nothing_when_the_index_does_not_exist_yet(): void
    {
        $this->assertSame([], Customer::search('kowalski')->get()->all());
    }

    #[Test]
    public function it_searches_models_with_string_keys(): void
    {
        Article::create(['title' => 'Wymiana rozrządu']);
        Article::create(['title' => 'Wymiana oleju']);

        $this->assertSame(
            ['Wymiana oleju'],
            Article::search('oleju')->get()->pluck('title')->all()
        );
    }

    #[Test]
    public function it_honours_an_explicit_limit(): void
    {
        Customer::create(['name' => 'Kowalski jeden']);
        Customer::create(['name' => 'Kowalski dwa']);
        Customer::create(['name' => 'Kowalski trzy']);

        $this->assertCount(2, Customer::search('kowalski')->take(2)->get());
    }

    #[Test]
    public function it_hands_the_raw_result_to_scouts_callback(): void
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
