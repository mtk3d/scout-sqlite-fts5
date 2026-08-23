<?php

declare(strict_types=1);

namespace ScoutFts5\Console;

use Illuminate\Console\Command;
use ScoutFts5\Console\Concerns\ResolvesSearchableModels;
use ScoutFts5\Support\Schema;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'scout:fts5-rebuild')]
class RebuildCommand extends Command
{
    use ResolvesSearchableModels;

    protected $signature = 'scout:fts5-rebuild
        {model?* : The searchable models to rebuild}
        {--no-optimize : Skip merging index segments afterwards}';

    protected $description = 'Drop, recreate and repopulate the FTS5 index tables of your searchable models';

    public function handle(Schema $schema): int
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
