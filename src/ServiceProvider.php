<?php

declare(strict_types=1);

namespace ScoutFts5;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Laravel\Scout\EngineManager;
use ScoutFts5\Console\CreateCommand;
use ScoutFts5\Console\DropCommand;
use ScoutFts5\Console\OptimizeCommand;
use ScoutFts5\Console\RebuildCommand;
use ScoutFts5\Contracts\Normalizer;
use ScoutFts5\Exceptions\ScoutFts5Exception;
use ScoutFts5\Support\Schema;

/**
 * Registers the `sqlite-fts5` Scout driver and everything it is built from.
 *
 * Every service is bound so it can be swapped out: bind your own
 * {@see Normalizer} to change how text is folded, or your own
 * {@see Seeker} to change how the search cascade behaves.
 */
class ServiceProvider extends BaseServiceProvider
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

        $this->app->bind(Schema::class, fn (Container $app) => new Schema(
            $this->connection($app),
            $app->make(SearchConfiguration::class),
        ));

        $this->app->bind(Indexer::class, fn (Container $app) => new Indexer(
            $this->connection($app),
            $app->make(Schema::class),
            $app->make(Normalizer::class),
            $app->make(SearchConfiguration::class),
        ));

        $this->app->bind(Seeker::class, fn (Container $app) => new Seeker(
            $this->connection($app),
            $app->make(Schema::class),
            $app->make(Normalizer::class),
            $app->make(SearchConfiguration::class),
        ));

        $this->app->bind(Engine::class, fn (Container $app) => new Engine(
            $app->make(Indexer::class),
            $app->make(Seeker::class),
            $app->make(Schema::class),
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
                CreateCommand::class,
                DropCommand::class,
                OptimizeCommand::class,
                RebuildCommand::class,
            ]);
        }

        $engine = fn (Container $app) => $app->make(Engine::class);

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
