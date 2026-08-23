<?php

declare(strict_types=1);

namespace ScoutFts5\Console;

use Illuminate\Console\Command;
use ScoutFts5\Console\Concerns\ResolvesSearchableModels;
use ScoutFts5\Support\Schema;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'scout:fts5-drop')]
class DropCommand extends Command
{
    use ResolvesSearchableModels;

    protected $signature = 'scout:fts5-drop {model?* : The searchable models to drop index tables for}';

    protected $description = 'Drop the FTS5 index tables of your searchable models';

    public function handle(Schema $schema): int
    {
        foreach ($this->searchableModels() as $model) {
            $table = $schema->tableFor($model);

            $schema->drop($table)
                ? $this->components->info("Dropped [{$table}].")
                : $this->components->twoColumnDetail($table, '<fg=gray>does not exist</>');
        }

        return self::SUCCESS;
    }
}
