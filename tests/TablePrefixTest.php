<?php

declare(strict_types=1);

namespace Mtk3d\Scout\Fts5\Tests;

use Mtk3d\Scout\Fts5\Support\Fts5Schema;
use Mtk3d\Scout\Fts5\Tests\Stubs\Customer;
use PHPUnit\Framework\Attributes\Test;

/**
 * The query builder applies the connection's table prefix on its own, while
 * every hand-written SQL fragment has to apply it itself. When the two
 * disagree, nothing works — so this runs the whole path with a prefix set.
 */
class TablePrefixTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.testing.prefix', 'app_');
    }

    #[Test]
    public function it_creates_the_index_under_the_prefixed_name(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->assertTrue($this->app->make(Fts5Schema::class)->exists('customers_fts'));

        $tables = $this->app->make('db')->connection()->select(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '%customers_fts'"
        );

        $this->assertSame(['app_customers_fts'], array_map(fn ($row) => $row->name, $tables));
    }

    #[Test]
    public function it_searches_ranks_and_filters_through_the_prefix(): void
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

    #[Test]
    public function it_falls_back_through_the_prefix_too(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->assertSame('typo', Customer::search('kowalsky')->raw()->pass());
        $this->assertSame('trigram', Customer::search('kowerlski')->raw()->pass());
    }

    #[Test]
    public function its_commands_work_through_the_prefix(): void
    {
        Customer::withoutSyncingToSearch(fn () => Customer::create(['name' => 'Jan Kowalski']));

        $this->artisan('scout:fts5-rebuild', ['model' => [Customer::class]])->assertSuccessful();

        $this->assertCount(1, Customer::search('kowalski')->get());

        $this->artisan('scout:fts5-drop', ['model' => [Customer::class]])->assertSuccessful();

        $this->assertFalse($this->app->make(Fts5Schema::class)->exists('customers_fts'));
    }
}
