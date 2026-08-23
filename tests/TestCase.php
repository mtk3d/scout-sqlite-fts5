<?php

declare(strict_types=1);

namespace ScoutFts5\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\ScoutServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ScoutFts5\ServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ScoutServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('scout.driver', 'sqlite-fts5');
        $app['config']->set('scout.queue', false);
        $app['config']->set('scout.soft_delete', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('city')->nullable();
            $table->string('notes')->nullable();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->softDeletes();
            $table->timestamps();
        });
    }
}
