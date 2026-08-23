<?php

declare(strict_types=1);

namespace ScoutFts5\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\UsesClass;
use ScoutFts5\Console\Concerns\ResolvesSearchableModels;
use ScoutFts5\Console\CreateCommand;
use ScoutFts5\Console\DropCommand;
use ScoutFts5\Console\OptimizeCommand;
use ScoutFts5\Console\RebuildCommand;
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
use ScoutFts5\Tests\Stubs\Article;
use ScoutFts5\Tests\Stubs\Customer;

#[CoversClass(CreateCommand::class)]
#[CoversClass(DropCommand::class)]
#[CoversClass(OptimizeCommand::class)]
#[CoversClass(RebuildCommand::class)]
#[CoversTrait(ResolvesSearchableModels::class)]
#[UsesClass(Engine::class)]
#[UsesClass(Indexer::class)]
#[UsesClass(Seeker::class)]
#[UsesClass(ServiceProvider::class)]
#[UsesClass(SearchConfiguration::class)]
#[UsesClass(SearchResult::class)]
#[UsesClass(Schema::class)]
#[UsesClass(MatchQuery::class)]
#[UsesClass(SearchPass::class)]
#[UsesClass(Tokens::class)]
#[UsesClass(DiacriticsNormalizer::class)]
class CommandsTest extends TestCase
{
    public function testItCreatesTheIndexOfANamedModel(): void
    {
        $this->artisan('scout:fts5-create', ['model' => [Customer::class]])->assertSuccessful();

        $this->assertTrue($this->schema()->exists('customers_fts'));
        $this->assertContains('tenant_id', $this->schema()->columns('customers_fts'));
    }

    public function testItDiscoversSearchableModelsWhenNoneAreNamed(): void
    {
        config()->set('scout-fts5.model_paths', [__DIR__.'/Stubs']);

        $this->artisan('scout:fts5-create')->assertSuccessful();

        $this->assertTrue($this->schema()->exists('customers_fts'));
        $this->assertTrue($this->schema()->exists('articles_fts'));
        $this->assertTrue($this->schema()->exists('posts_fts'));
    }

    public function testItUsesTheConfiguredModelListOverScanning(): void
    {
        config()->set('scout-fts5.models', [Article::class]);
        config()->set('scout-fts5.model_paths', [__DIR__.'/Stubs']);

        $this->artisan('scout:fts5-create')->assertSuccessful();

        $this->assertTrue($this->schema()->exists('articles_fts'));
        $this->assertFalse($this->schema()->exists('customers_fts'));
    }

    public function testItLeavesAnExistingIndexAloneUnlessAskedForAFreshOne(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->artisan('scout:fts5-create', ['model' => [Customer::class]])->assertSuccessful();
        $this->assertSame(1, $this->rowsIn('customers_fts'));

        $this->artisan('scout:fts5-create', ['model' => [Customer::class], '--fresh' => true])->assertSuccessful();
        $this->assertSame(0, $this->rowsIn('customers_fts'));
    }

    public function testItDropsAnIndex(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->artisan('scout:fts5-drop', ['model' => [Customer::class]])->assertSuccessful();

        $this->assertFalse($this->schema()->exists('customers_fts'));
    }

    public function testItRebuildsAnIndexFromTheModelsTable(): void
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

    public function testItOptimizesAnIndex(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->artisan('scout:fts5-optimize', ['model' => [Customer::class]])->assertSuccessful();

        $this->assertCount(1, Customer::search('kowalski')->get());
    }

    public function testItSkipsClassesThatAreNotSearchableModels(): void
    {
        $this->artisan('scout:fts5-create', ['model' => [self::class]])
            ->expectsOutputToContain('is not a searchable Eloquent model')
            ->assertSuccessful();
    }

    public function testItSaysSoWhenThereIsNothingToDo(): void
    {
        config()->set('scout-fts5.model_paths', [__DIR__.'/nowhere']);

        $this->artisan('scout:fts5-create')
            ->expectsOutputToContain('No searchable models found')
            ->assertSuccessful();
    }

    private function schema(): Schema
    {
        return $this->app->make(Schema::class);
    }

    private function rowsIn(string $table): int
    {
        return $this->app->make('db')->connection()->table($table)->count();
    }
}
