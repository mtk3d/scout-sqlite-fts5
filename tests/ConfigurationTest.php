<?php

declare(strict_types=1);

namespace Mtk3d\Scout\Fts5\Tests;

use Mtk3d\Scout\Fts5\Contracts\Normalizer;
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

#[CoversClass(SearchConfiguration::class)]
#[CoversClass(Fts5ServiceProvider::class)]
#[UsesClass(Fts5Engine::class)]
#[UsesClass(Fts5Indexer::class)]
#[UsesClass(Fts5Seeker::class)]
#[UsesClass(SearchResult::class)]
#[UsesClass(Fts5Schema::class)]
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

        $this->assertTrue($this->app->make(Fts5Schema::class)->exists('customers_search'));
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

        $this->app->make(Fts5Schema::class);
    }
}
