<?php

declare(strict_types=1);

namespace Wrenchr\Scout\Fts5\Console\Concerns;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Turns the optional `model` argument of the `scout:fts5-*` commands into a
 * list of model instances, discovering them from the configured paths when the
 * caller did not name any.
 */
trait ResolvesSearchableModels
{
    /**
     * @return Model[]
     */
    protected function searchableModels(): array
    {
        $names = array_filter((array) $this->argument('model'));

        if ($names === []) {
            $names = $this->discoverModels();
        }

        $models = [];

        foreach ($names as $name) {
            if (! $this->isSearchable($name)) {
                $this->components->warn("[{$name}] is not a searchable Eloquent model, skipping.");

                continue;
            }

            $models[] = new $name;
        }

        if ($models === []) {
            $this->components->warn(
                'No searchable models found. Name one explicitly, or point `scout-fts5.model_paths` at your models.'
            );
        }

        return $models;
    }

    /**
     * The models listed in the configuration, or the ones found by scanning
     * the configured paths.
     *
     * @return string[]
     */
    protected function discoverModels(): array
    {
        $configured = array_filter((array) config('scout-fts5.models', []));

        if ($configured !== []) {
            return $configured;
        }

        $classes = [];

        foreach ((array) config('scout-fts5.model_paths', []) as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if ($file->isDir() || $file->getExtension() !== 'php') {
                    continue;
                }

                $class = $this->classInFile($file->getPathname());

                if ($class !== null && $this->isSearchable($class)) {
                    $classes[] = $class;
                }
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * Reads the fully qualified class name declared in a PHP file, without
     * assuming the file sits where PSR-4 would put it.
     */
    protected function classInFile(string $path): ?string
    {
        $contents = (string) file_get_contents($path);

        preg_match('/^namespace\s+([^;{\s]+)/m', $contents, $namespace);
        preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $contents, $class);

        if (! isset($class[1])) {
            return null;
        }

        return isset($namespace[1]) ? $namespace[1].'\\'.$class[1] : $class[1];
    }

    /**
     * Whether the given class is an Eloquent model that Scout can index.
     */
    protected function isSearchable(string $class): bool
    {
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return false;
        }

        if ((new ReflectionClass($class))->isAbstract()) {
            return false;
        }

        return in_array(Searchable::class, class_uses_recursive($class), true);
    }
}
