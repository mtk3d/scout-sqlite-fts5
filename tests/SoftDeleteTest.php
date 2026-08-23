<?php

declare(strict_types=1);

namespace Mtk3d\Scout\Fts5\Tests;

use Mtk3d\Scout\Fts5\Support\Fts5Schema;
use Mtk3d\Scout\Fts5\Tests\Stubs\Post;
use PHPUnit\Framework\Attributes\Test;

class SoftDeleteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('scout.soft_delete', true);
    }

    #[Test]
    public function it_adds_a_soft_delete_column_to_the_index(): void
    {
        Post::create(['title' => 'Wymiana rozrządu']);

        $this->assertContains(
            Fts5Schema::SOFT_DELETE_COLUMN,
            $this->app->make(Fts5Schema::class)->columns('posts_fts')
        );
    }

    #[Test]
    public function it_hides_trashed_models_but_keeps_them_indexed(): void
    {
        $post = Post::create(['title' => 'Wymiana rozrządu']);

        $post->delete();

        $this->assertCount(0, Post::search('wymiana')->get());
        $this->assertCount(1, $this->app->make('db')->connection()->table('posts_fts')->get());
    }

    #[Test]
    public function it_finds_trashed_models_when_asked_to(): void
    {
        $post = Post::create(['title' => 'Wymiana rozrządu']);
        $post->delete();

        $this->assertCount(1, Post::search('wymiana')->withTrashed()->get());
        $this->assertCount(1, Post::search('wymiana')->onlyTrashed()->get());
    }

    #[Test]
    public function it_shows_restored_models_again(): void
    {
        $post = Post::create(['title' => 'Wymiana rozrządu']);
        $post->delete();
        $post->restore();

        $this->assertCount(1, Post::search('wymiana')->get());
    }
}
