<?php

declare(strict_types=1);

namespace Mtk3d\Scout\Fts5\Tests;

use Mtk3d\Scout\Fts5\Support\Fts5Schema;
use Mtk3d\Scout\Fts5\Tests\Stubs\Article;
use Mtk3d\Scout\Fts5\Tests\Stubs\Customer;
use PHPUnit\Framework\Attributes\Test;

class CommandsTest extends TestCase
{
    #[Test]
    public function it_creates_the_index_of_a_named_model(): void
    {
        $this->artisan('scout:fts5-create', ['model' => [Customer::class]])->assertSuccessful();

        $this->assertTrue($this->schema()->exists('customers_fts'));
        $this->assertContains('tenant_id', $this->schema()->columns('customers_fts'));
    }

    #[Test]
    public function it_discovers_searchable_models_when_none_are_named(): void
    {
        config()->set('scout-fts5.model_paths', [__DIR__.'/Stubs']);

        $this->artisan('scout:fts5-create')->assertSuccessful();

        $this->assertTrue($this->schema()->exists('customers_fts'));
        $this->assertTrue($this->schema()->exists('articles_fts'));
        $this->assertTrue($this->schema()->exists('posts_fts'));
    }

    #[Test]
    public function it_uses_the_configured_model_list_over_scanning(): void
    {
        config()->set('scout-fts5.models', [Article::class]);
        config()->set('scout-fts5.model_paths', [__DIR__.'/Stubs']);

        $this->artisan('scout:fts5-create')->assertSuccessful();

        $this->assertTrue($this->schema()->exists('articles_fts'));
        $this->assertFalse($this->schema()->exists('customers_fts'));
    }

    #[Test]
    public function it_leaves_an_existing_index_alone_unless_asked_for_a_fresh_one(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->artisan('scout:fts5-create', ['model' => [Customer::class]])->assertSuccessful();
        $this->assertSame(1, $this->rowsIn('customers_fts'));

        $this->artisan('scout:fts5-create', ['model' => [Customer::class], '--fresh' => true])->assertSuccessful();
        $this->assertSame(0, $this->rowsIn('customers_fts'));
    }

    #[Test]
    public function it_drops_an_index(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->artisan('scout:fts5-drop', ['model' => [Customer::class]])->assertSuccessful();

        $this->assertFalse($this->schema()->exists('customers_fts'));
    }

    #[Test]
    public function it_rebuilds_an_index_from_the_models_table(): void
    {
        Customer::withoutSyncingToSearch(function () {
            Customer::create(['name' => 'Jan Kowalski']);
            Customer::create(['name' => 'Anna Nowak']);
        });

        $this->assertCount(0, Customer::search('kowalski')->get());

        $this->artisan('scout:fts5-rebuild', ['model' => [Customer::class]])->assertSuccessful();

        $this->assertCount(1, Customer::search('kowalski')->get());
        $this->assertSame(2, $this->rowsIn('customers_fts'));
    }

    #[Test]
    public function it_optimizes_an_index(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->artisan('scout:fts5-optimize', ['model' => [Customer::class]])->assertSuccessful();

        $this->assertCount(1, Customer::search('kowalski')->get());
    }

    #[Test]
    public function it_skips_classes_that_are_not_searchable_models(): void
    {
        $this->artisan('scout:fts5-create', ['model' => [self::class]])
            ->expectsOutputToContain('is not a searchable Eloquent model')
            ->assertSuccessful();
    }

    #[Test]
    public function it_says_so_when_there_is_nothing_to_do(): void
    {
        config()->set('scout-fts5.model_paths', [__DIR__.'/nowhere']);

        $this->artisan('scout:fts5-create')
            ->expectsOutputToContain('No searchable models found')
            ->assertSuccessful();
    }

    private function schema(): Fts5Schema
    {
        return $this->app->make(Fts5Schema::class);
    }

    private function rowsIn(string $table): int
    {
        return $this->app->make('db')->connection()->table($table)->count();
    }
}
