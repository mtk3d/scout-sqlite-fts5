<?php

declare(strict_types=1);

namespace Wrenchr\Scout\Fts5\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Wrenchr\Scout\Fts5\Console\Concerns\ResolvesSearchableModels;
use Wrenchr\Scout\Fts5\Support\Fts5Schema;

#[AsCommand(name: 'scout:fts5-rebuild')]
class Fts5RebuildCommand extends Command
{
    use ResolvesSearchableModels;

    protected $signature = 'scout:fts5-rebuild
        {model?* : The searchable models to rebuild}
        {--no-optimize : Skip merging index segments afterwards}';

    protected $description = 'Drop, recreate and repopulate the FTS5 index tables of your searchable models';

    public function handle(Fts5Schema $schema): int
    {
        foreach ($this->searchableModels() as $model) {
            $table = $schema->tableFor($model);

            $this->components->task("Rebuilding [{$table}]", function () use ($schema, $model, $table) {
                $schema->drop($table);
                $schema->createFor($model);

                $this->callSilent('scout:import', ['model' => $model::class]);

                if (! $this->option('no-optimize')) {
                    $schema->optimize($table);
                }

                return true;
            });
        }

        return self::SUCCESS;
    }
}
