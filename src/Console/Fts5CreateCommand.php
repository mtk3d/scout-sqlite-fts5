<?php

declare(strict_types=1);

namespace Wrenchr\Scout\Fts5\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Console\Attribute\AsCommand;
use Wrenchr\Scout\Fts5\Console\Concerns\ResolvesSearchableModels;
use Wrenchr\Scout\Fts5\Support\Fts5Schema;

#[AsCommand(name: 'scout:fts5-create')]
class Fts5CreateCommand extends Command
{
    use ResolvesSearchableModels;

    protected $signature = 'scout:fts5-create
        {model?* : The searchable models to create index tables for}
        {--fresh : Drop and recreate tables that already exist}';

    protected $description = 'Create the FTS5 index tables of your searchable models';

    public function handle(Fts5Schema $schema): int
    {
        foreach ($this->searchableModels() as $model) {
            $table = $schema->tableFor($model);

            if ($this->option('fresh')) {
                $schema->drop($table);
            }

            if ($schema->createFor($model)) {
                $this->components->info("Created [{$table}].");

                continue;
            }

            $this->reportExisting($schema, $model, $table);
        }

        return self::SUCCESS;
    }

    /**
     * Reports on a table that was already there, pointing out the case where
     * its columns no longer match what the model declares.
     */
    private function reportExisting(Fts5Schema $schema, Model $model, string $table): void
    {
        if ($schema->matchesModel($model)) {
            $this->components->twoColumnDetail($table, '<fg=gray>already exists</>');

            return;
        }

        $this->components->warn(
            "[{$table}] exists but is missing filter columns declared by [".$model::class.']. '
            .'FTS5 tables cannot be altered — rerun with --fresh, then reimport.'
        );
    }
}
