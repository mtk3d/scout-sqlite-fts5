<?php

declare(strict_types=1);

namespace Mtk3d\Scout\Fts5\Tests;

use Mtk3d\Scout\Fts5\Contracts\Normalizer;
use Mtk3d\Scout\Fts5\Exceptions\ScoutFts5Exception;
use Mtk3d\Scout\Fts5\Support\Fts5Schema;
use Mtk3d\Scout\Fts5\Tests\Stubs\Customer;
use PHPUnit\Framework\Attributes\Test;

class ConfigurationTest extends TestCase
{
    #[Test]
    public function it_can_turn_off_the_fuzzy_passes(): void
    {
        config()->set('scout-fts5.passes', ['prefix' => true, 'typo' => false, 'any' => false, 'trigram' => false]);

        Customer::create(['name' => 'Jan Kowalski']);

        $this->assertCount(1, Customer::search('kowal')->get());
        $this->assertCount(0, Customer::search('kowalsky')->get());
    }

    #[Test]
    public function its_typo_pass_gives_up_on_a_tail_that_is_too_wrong(): void
    {
        config()->set('scout-fts5.passes', ['prefix' => true, 'typo' => true, 'any' => false, 'trigram' => false]);

        Customer::create(['name' => 'Jan Kowalski']);

        // Cutting the default two characters leaves "kowalxy", which misses.
        $this->assertCount(0, Customer::search('kowalxyzw')->get());
    }

    #[Test]
    public function it_can_be_told_how_forgiving_the_typo_pass_should_be(): void
    {
        config()->set('scout-fts5.passes', ['prefix' => true, 'typo' => true, 'any' => false, 'trigram' => false]);
        config()->set('scout-fts5.typo.trim', 4);

        Customer::create(['name' => 'Jan Kowalski']);

        // Cutting four gets back to "kowal", which does not.
        $this->assertCount(1, Customer::search('kowalxyzw')->get());
    }

    #[Test]
    public function it_can_be_told_how_much_of_a_word_a_substring_match_needs(): void
    {
        config()->set('scout-fts5.trigram.min_ratio', 1.0);

        Customer::create(['name' => 'Jan Kowalski']);

        // Demanding every substring leaves no room for the typo that made the
        // substring pass necessary in the first place.
        $this->assertCount(0, Customer::search('kowerlski')->get());
    }

    #[Test]
    public function it_uses_a_custom_normalizer(): void
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

    #[Test]
    public function it_uses_a_custom_table_suffix(): void
    {
        config()->set('scout-fts5.suffix', '_search');

        Customer::create(['name' => 'Jan Kowalski']);

        $this->assertTrue($this->app->make(Fts5Schema::class)->exists('customers_search'));
        $this->assertCount(1, Customer::search('kowalski')->get());
    }

    #[Test]
    public function it_uses_a_custom_tokenizer(): void
    {
        config()->set('scout-fts5.tokenizer', 'porter unicode61 remove_diacritics 2');

        Customer::create(['name' => 'Jan Kowalski', 'notes' => 'engineering']);

        // The porter stemmer reduces both sides of the search to one stem.
        $this->assertCount(1, Customer::search('engineer')->get());
    }

    #[Test]
    public function it_refuses_to_run_on_a_connection_that_is_not_sqlite(): void
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
