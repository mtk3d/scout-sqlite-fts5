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
use Mtk3d\Scout\Fts5\Tests\Stubs\Post;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(Fts5Schema::class)]
#[CoversClass(Fts5Indexer::class)]
#[UsesClass(Fts5Engine::class)]
#[UsesClass(Fts5Seeker::class)]
#[UsesClass(Fts5ServiceProvider::class)]
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
            Fts5Schema::SOFT_DELETE_COLUMN,
            $this->app->make(Fts5Schema::class)->columns('posts_fts')
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
