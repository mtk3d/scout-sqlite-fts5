<?php

declare(strict_types=1);

namespace Wrenchr\Scout\Fts5\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Wrenchr\Scout\Fts5\Console\Concerns\ResolvesSearchableModels;
use Wrenchr\Scout\Fts5\Support\Fts5Schema;

#[AsCommand(name: 'scout:fts5-drop')]
class Fts5DropCommand extends Command
{
    use ResolvesSearchableModels;

    protected $signature = 'scout:fts5-drop {model?* : The searchable models to drop index tables for}';

    protected $description = 'Drop the FTS5 index tables of your searchable models';

    public function handle(Fts5Schema $schema): int
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
