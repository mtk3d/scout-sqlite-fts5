<?php

declare(strict_types=1);

namespace Wrenchr\Scout\Fts5;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;
use Wrenchr\Scout\Fts5\Console\Fts5CreateCommand;
use Wrenchr\Scout\Fts5\Console\Fts5DropCommand;
use Wrenchr\Scout\Fts5\Console\Fts5OptimizeCommand;
use Wrenchr\Scout\Fts5\Console\Fts5RebuildCommand;
use Wrenchr\Scout\Fts5\Contracts\Normalizer;
use Wrenchr\Scout\Fts5\Exceptions\ScoutFts5Exception;
use Wrenchr\Scout\Fts5\Support\Fts5Schema;

/**
 * Registers the `sqlite-fts5` Scout driver and everything it is built from.
 *
 * Every service is bound so it can be swapped out: bind your own
 * {@see Normalizer} to change how text is folded, or your own
 * {@see Fts5Seeker} to change how the search cascade behaves.
 */
class Fts5ServiceProvider extends ServiceProvider
{
    /**
     * The name the driver is registered under, plus a shorter alias.
     */
    public const DRIVER = 'sqlite-fts5';

    public const ALIAS = 'fts5';

    /**
     * Register the package's services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scout-fts5.php', 'scout-fts5');

        $this->app->singleton(SearchConfiguration::class, fn (Container $app) => SearchConfiguration::fromArray(
            $app->make('config')->get('scout-fts5', [])
        ));

        $this->app->bind(Normalizer::class, function (Container $app) {
            $normalizer = $app->make('config')->get('scout-fts5.normalizer');

            return $app->make($normalizer);
        });

        $this->app->bind(Fts5Schema::class, fn (Container $app) => new Fts5Schema(
            $this->connection($app),
            $app->make(SearchConfiguration::class),
        ));

        $this->app->bind(Fts5Indexer::class, fn (Container $app) => new Fts5Indexer(
            $this->connection($app),
            $app->make(Fts5Schema::class),
            $app->make(Normalizer::class),
            $app->make(SearchConfiguration::class),
        ));

        $this->app->bind(Fts5Seeker::class, fn (Container $app) => new Fts5Seeker(
            $this->connection($app),
            $app->make(Fts5Schema::class),
            $app->make(Normalizer::class),
            $app->make(SearchConfiguration::class),
        ));

        $this->app->bind(Fts5Engine::class, fn (Container $app) => new Fts5Engine(
            $app->make(Fts5Indexer::class),
            $app->make(Fts5Seeker::class),
            $app->make(Fts5Schema::class),
        ));
    }

    /**
     * Boot the package's services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/scout-fts5.php' => config_path('scout-fts5.php'),
            ], ['config', 'scout-fts5-config']);

            $this->commands([
                Fts5CreateCommand::class,
                Fts5DropCommand::class,
                Fts5OptimizeCommand::class,
                Fts5RebuildCommand::class,
            ]);
        }

        $engine = fn (Container $app) => $app->make(Fts5Engine::class);

        $this->app->make(EngineManager::class)
            ->extend(self::DRIVER, $engine)
            ->extend(self::ALIAS, $engine);
    }

    /**
     * Resolves the SQLite connection the index lives on.
     *
     * @throws ScoutFts5Exception
     */
    private function connection(Container $app): ConnectionInterface
    {
        $connection = $app->make(DatabaseManager::class)
            ->connection($app->make('config')->get('scout-fts5.connection'));

        if ($connection->getDriverName() !== 'sqlite') {
            throw ScoutFts5Exception::unsupportedDriver($connection->getDriverName());
        }

        return $connection;
    }
}
