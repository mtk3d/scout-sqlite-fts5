<?php

declare(strict_types=1);

namespace ScoutFts5\Tests;

use Illuminate\Database\ConnectionInterface;
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
 * The behaviour at the edges, pinned down so the documentation cannot drift
 * from it. Every case here is one the docs describe.
 */
#[CoversClass(Seeker::class)]
#[CoversClass(Indexer::class)]
#[UsesClass(Engine::class)]
#[UsesClass(ServiceProvider::class)]
#[UsesClass(SearchConfiguration::class)]
#[UsesClass(SearchResult::class)]
#[UsesClass(Schema::class)]
#[UsesClass(MatchQuery::class)]
#[UsesClass(SearchPass::class)]
#[UsesClass(Tokens::class)]
#[UsesClass(DiacriticsNormalizer::class)]
class EdgeCaseTest extends TestCase
{
    public function testItIndexesAZeroWithoutMistakingItForEmpty(): void
    {
        // "0" is falsy in PHP but meaningful in data — an address number, a
        // reading, an account balance.
        Customer::create(['name' => '0', 'city' => 'Zero', 'notes' => '']);

        $this->assertSame('0 zero', $this->db()->table('customers_fts')->value('content'));
        $this->assertCount(1, Customer::search('0')->get());
    }

    public function testItMatchesCjkOnlyFromTheStartOfARun(): void
    {
        // The unicode61 tokenizer splits on non-alphanumerics, and a run of CJK
        // has none — so 東京都渋谷区 is a single token that only prefix queries
        // can reach into.
        Customer::create(['name' => '東京都渋谷区']);

        $this->assertCount(1, Customer::search('東京')->get());
        $this->assertCount(0, Customer::search('渋谷')->get());
    }

    public function testSmallerGramsMakeCjkSubstringsReachable(): void
    {
        // Two-character words are below the default substring size, so the
        // substring pass skips them entirely. Lowering the size brings CJK
        // back within reach.
        config()->set('scout-fts5.trigram.size', 2);

        Customer::create(['name' => '東京都渋谷区']);

        $result = Customer::search('渋谷')->raw();

        $this->assertSame('trigram', $result->pass());
        $this->assertSame(1, $result->total());
    }

    public function testItApproximatesSuffixStrippingForInflectedLanguages(): void
    {
        // There is no stemmer. The typo pass shortens a word and matches it as
        // a prefix, which lands close to suffix stripping for languages that
        // inflect the ending — by accident rather than by design.
        Customer::create(['name' => 'Serwis', 'notes' => 'Wymiana rozrządu']);

        foreach (['wymiana', 'wymiany', 'wymianę', 'wymianie', 'rozrząd', 'rozrządem'] as $form) {
            $this->assertSame(1, Customer::search($form)->raw()->total(), "nie znalazł formy: {$form}");
        }
    }

    public function testTheCascadeCanReachUnrelatedWordsThatLookAlike(): void
    {
        // The other side of the same coin. Nothing here knows what a root is:
        // "kowalski" and "kowalczyk" share three of six substrings, which
        // clears the threshold, so a search for one reaches the other. A
        // stemmer would keep them apart; the cascade only knows characters.
        Customer::create(['name' => 'Kowalczyk']);

        $this->assertSame('trigram', Customer::search('kowalski')->raw()->pass());
        $this->assertSame(1, Customer::search('kowalski')->raw()->total());
    }

    public function testItMatchesAlphabetsThatAreNeitherLatinNorCjk(): void
    {
        Customer::create(['name' => 'Ковальский Ян']);

        $this->assertCount(1, Customer::search('ковальский')->get());
        $this->assertCount(1, Customer::search('коваль')->get());
    }

    public function testItFindsAPhoneNumberTypedWithoutItsPunctuation(): void
    {
        Customer::create(['name' => 'Nowak', 'notes' => '+48 601-234-567']);

        // Typed with separators, the digits are their own words and the strict
        // pass answers. Typed as one run, no token matches and the substring
        // pass recognises enough of it.
        $this->assertSame('prefix', Customer::search('601-234')->raw()->pass());
        $this->assertSame('trigram', Customer::search('601234567')->raw()->pass());
        $this->assertCount(1, Customer::search('601234567')->get());
    }

    public function testItFindsNothingForAQueryTooShortToFallBackOn(): void
    {
        Customer::create(['name' => 'Kowalski']);

        // "ko" still prefixes a real token…
        $this->assertSame('prefix', Customer::search('ko')->raw()->pass());

        // …but a short query matching no token has no substrings to fall back
        // on, so the cascade runs out rather than matching everything.
        $this->assertNull(Customer::search('xy')->raw()->pass());
    }

    public function testItReportsTheTotalOnAPageBeyondTheLast(): void
    {
        Customer::create(['name' => 'Kowalski']);

        $page = Customer::search('kowalski')->paginate(10, 'page', 99);

        $this->assertCount(0, $page->items());
        $this->assertSame(1, $page->total());
    }

    public function testPaginationOverridesAnExplicitLimit(): void
    {
        foreach (range(1, 3) as $n) {
            Customer::create(['name' => "Kowalski {$n}"]);
        }

        // take() sets the page size for an unpaginated search; once a page size
        // is given it wins, which is how Scout's other engines behave too.
        $this->assertCount(1, Customer::search('kowalski')->take(1)->get());
        $this->assertCount(3, Customer::search('kowalski')->take(1)->paginate(10));
    }

    public function testAnEmptyWhereInMatchesNothing(): void
    {
        Customer::create(['name' => 'Kowalski', 'tenant_id' => 1]);

        $this->assertCount(0, Customer::search('kowalski')->whereIn('tenant_id', [])->get());
    }

    private function db(): ConnectionInterface
    {
        return $this->app->make('db')->connection();
    }
}
