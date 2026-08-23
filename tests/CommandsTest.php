<?php

declare(strict_types=1);

namespace Mtk3d\Scout\Fts5\Tests;

use Mtk3d\Scout\Fts5\Console\Concerns\ResolvesSearchableModels;
use Mtk3d\Scout\Fts5\Console\Fts5CreateCommand;
use Mtk3d\Scout\Fts5\Console\Fts5DropCommand;
use Mtk3d\Scout\Fts5\Console\Fts5OptimizeCommand;
use Mtk3d\Scout\Fts5\Console\Fts5RebuildCommand;
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
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(Fts5CreateCommand::class)]
#[CoversClass(Fts5DropCommand::class)]
#[CoversClass(Fts5OptimizeCommand::class)]
#[CoversClass(Fts5RebuildCommand::class)]
#[CoversTrait(ResolvesSearchableModels::class)]
#[UsesClass(Fts5Engine::class)]
#[UsesClass(Fts5Indexer::class)]
#[UsesClass(Fts5Seeker::class)]
#[UsesClass(Fts5ServiceProvider::class)]
#[UsesClass(SearchConfiguration::class)]
#[UsesClass(SearchResult::class)]
#[UsesClass(Fts5Schema::class)]
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

    private function schema(): Fts5Schema
    {
        return $this->app->make(Fts5Schema::class);
    }

    private function rowsIn(string $table): int
    {
        return $this->app->make('db')->connection()->table($table)->count();
    }
}
