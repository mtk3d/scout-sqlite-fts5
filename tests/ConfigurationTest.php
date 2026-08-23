<?php

declare(strict_types=1);

namespace ScoutFts5\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ScoutFts5\Contracts\Normalizer;
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

#[CoversClass(SearchConfiguration::class)]
#[CoversClass(ServiceProvider::class)]
#[UsesClass(Engine::class)]
#[UsesClass(Indexer::class)]
#[UsesClass(Seeker::class)]
#[UsesClass(SearchResult::class)]
#[UsesClass(Schema::class)]
#[UsesClass(MatchQuery::class)]
#[UsesClass(SearchPass::class)]
#[UsesClass(Tokens::class)]
#[UsesClass(DiacriticsNormalizer::class)]
#[UsesClass(ScoutFts5Exception::class)]
class ConfigurationTest extends TestCase
{
    public function testItCanTurnOffTheFuzzyPasses(): void
    {
        config()->set('scout-fts5.passes', ['prefix' => true, 'typo' => false, 'any' => false, 'trigram' => false]);

        Customer::create(['name' => 'Jan Kowalski']);

        $this->assertCount(1, Customer::search('kowal')->get());
        $this->assertCount(0, Customer::search('kowalsky')->get());
    }

    public function testItsTypoPassGivesUpOnATailThatIsTooWrong(): void
    {
        config()->set('scout-fts5.passes', ['prefix' => true, 'typo' => true, 'any' => false, 'trigram' => false]);

        Customer::create(['name' => 'Jan Kowalski']);

        // Cutting the default two characters leaves "kowalxy", which misses.
        $this->assertCount(0, Customer::search('kowalxyzw')->get());
    }

    public function testItCanBeToldHowForgivingTheTypoPassShouldBe(): void
    {
        config()->set('scout-fts5.passes', ['prefix' => true, 'typo' => true, 'any' => false, 'trigram' => false]);
        config()->set('scout-fts5.typo.trim', 4);

        Customer::create(['name' => 'Jan Kowalski']);

        // Cutting four gets back to "kowal", which does not.
        $this->assertCount(1, Customer::search('kowalxyzw')->get());
    }

    public function testItCanBeToldHowMuchOfAWordASubstringMatchNeeds(): void
    {
        config()->set('scout-fts5.trigram.min_ratio', 1.0);

        Customer::create(['name' => 'Jan Kowalski']);

        // Demanding every substring leaves no room for the typo that made the
        // substring pass necessary in the first place.
        $this->assertCount(0, Customer::search('kowerlski')->get());
    }

    public function testItUsesACustomNormalizer(): void
    {
        $this->app->bind(Normalizer::class, fn () => new class implements Normalizer
        {
            public function normalize(string $text): string
            {
                return str_replace('strasse', 'str', mb_strtolower($text));
            }
        });

        Customer::create(['name' => 'Hauptstrasse 12']);

        $this->assertSame(
            'hauptstr 12',
            $this->app->make('db')->connection()->table('customers_fts')->value('content')
        );

        $this->assertCount(1, Customer::search('hauptstrasse')->get());
    }

    public function testItUsesACustomTableSuffix(): void
    {
        config()->set('scout-fts5.suffix', '_search');

        Customer::create(['name' => 'Jan Kowalski']);

        $this->assertTrue($this->app->make(Schema::class)->exists('customers_search'));
        $this->assertCount(1, Customer::search('kowalski')->get());
    }

    public function testItUsesACustomTokenizer(): void
    {
        config()->set('scout-fts5.tokenizer', 'porter unicode61 remove_diacritics 2');

        Customer::create(['name' => 'Jan Kowalski', 'notes' => 'engineering']);

        // The porter stemmer reduces both sides of the search to one stem.
        $this->assertCount(1, Customer::search('engineer')->get());
    }

    public function testItRefusesToRunOnAConnectionThatIsNotSqlite(): void
    {
        config()->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'forge',
        ]);
        config()->set('scout-fts5.connection', 'mysql');

        $this->expectException(ScoutFts5Exception::class);
        $this->expectExceptionMessageMatches('/requires an SQLite connection/');

        $this->app->make(Schema::class);
    }
}
