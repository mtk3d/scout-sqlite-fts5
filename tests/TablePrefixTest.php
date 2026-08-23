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

/**
 * The query builder applies the connection's table prefix on its own, while
 * every hand-written SQL fragment has to apply it itself. When the two
 * disagree, nothing works — so this runs the whole path with a prefix set.
 */
#[CoversClass(Fts5Schema::class)]
#[UsesClass(Fts5Engine::class)]
#[UsesClass(Fts5Indexer::class)]
#[UsesClass(Fts5Seeker::class)]
#[UsesClass(Fts5ServiceProvider::class)]
#[UsesClass(SearchConfiguration::class)]
#[UsesClass(SearchResult::class)]
#[UsesClass(MatchQuery::class)]
#[UsesClass(SearchPass::class)]
#[UsesClass(Tokens::class)]
#[UsesClass(DiacriticsNormalizer::class)]
class TablePrefixTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.testing.prefix', 'app_');
    }

    public function testItCreatesTheIndexUnderThePrefixedName(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->assertTrue($this->app->make(Fts5Schema::class)->exists('customers_fts'));

        $tables = $this->app->make('db')->connection()->select(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '%customers_fts'"
        );

        $this->assertSame(['app_customers_fts'], array_map(fn ($row) => $row->name, $tables));
    }

    public function testItSearchesRanksAndFiltersThroughThePrefix(): void
    {
        Customer::create(['name' => 'Jan Kowalski', 'tenant_id' => 1]);
        Customer::create(['name' => 'Adam Kowalski', 'tenant_id' => 2, 'notes' => str_repeat('lorem ipsum ', 40)]);

        $this->assertSame(
            ['Jan Kowalski', 'Adam Kowalski'],
            Customer::search('kowalski')->get()->pluck('name')->all()
        );

        $this->assertSame(
            ['Adam Kowalski'],
            Customer::search('kowalski')->where('tenant_id', 2)->get()->pluck('name')->all()
        );

        $this->assertSame(
            ['Adam Kowalski'],
            Customer::search('kowalski')->where('status', 'active')->latest('id')->take(1)->get()->pluck('name')->all()
        );
    }

    public function testItFallsBackThroughThePrefixToo(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->assertSame('typo', Customer::search('kowalsky')->raw()->pass());
        $this->assertSame('trigram', Customer::search('kowerlski')->raw()->pass());
    }

    public function testItsCommandsWorkThroughThePrefix(): void
    {
        Customer::withoutSyncingToSearch(fn () => Customer::create(['name' => 'Jan Kowalski']));

        $this->artisan('scout:fts5-rebuild', ['model' => [Customer::class]])->assertSuccessful();

        $this->assertCount(1, Customer::search('kowalski')->get());

        $this->artisan('scout:fts5-drop', ['model' => [Customer::class]])->assertSuccessful();

        $this->assertFalse($this->app->make(Fts5Schema::class)->exists('customers_fts'));
    }
}
