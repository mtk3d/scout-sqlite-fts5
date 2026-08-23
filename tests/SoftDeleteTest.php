<?php

declare(strict_types=1);

namespace ScoutFts5\Tests;

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
use ScoutFts5\Tests\Stubs\Post;

#[CoversClass(Schema::class)]
#[CoversClass(Indexer::class)]
#[UsesClass(Engine::class)]
#[UsesClass(Seeker::class)]
#[UsesClass(ServiceProvider::class)]
#[UsesClass(SearchConfiguration::class)]
#[UsesClass(SearchResult::class)]
#[UsesClass(MatchQuery::class)]
#[UsesClass(SearchPass::class)]
#[UsesClass(Tokens::class)]
#[UsesClass(DiacriticsNormalizer::class)]
class SoftDeleteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('scout.soft_delete', true);
    }

    public function testItAddsASoftDeleteColumnToTheIndex(): void
    {
        Post::create(['title' => 'Wymiana rozrządu']);

        $this->assertContains(
            Schema::SOFT_DELETE_COLUMN,
            $this->app->make(Schema::class)->columns('posts_fts')
        );
    }

    public function testItHidesTrashedModelsButKeepsThemIndexed(): void
    {
        $post = Post::create(['title' => 'Wymiana rozrządu']);

        $post->delete();

        $this->assertCount(0, Post::search('wymiana')->get());
        $this->assertCount(1, $this->app->make('db')->connection()->table('posts_fts')->get());
    }

    public function testItFindsTrashedModelsWhenAskedTo(): void
    {
        $post = Post::create(['title' => 'Wymiana rozrządu']);
        $post->delete();

        $this->assertCount(1, Post::search('wymiana')->withTrashed()->get());
        $this->assertCount(1, Post::search('wymiana')->onlyTrashed()->get());
    }

    public function testItShowsRestoredModelsAgain(): void
    {
        $post = Post::create(['title' => 'Wymiana rozrządu']);
        $post->delete();
        $post->restore();

        $this->assertCount(1, Post::search('wymiana')->get());
    }
}
