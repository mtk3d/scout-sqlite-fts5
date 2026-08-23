<?php

declare(strict_types=1);

namespace ScoutFts5\Console;

use Illuminate\Console\Command;
use ScoutFts5\Console\Concerns\ResolvesSearchableModels;
use ScoutFts5\Support\Schema;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'scout:fts5-optimize')]
class OptimizeCommand extends Command
{
    use ResolvesSearchableModels;

    protected $signature = 'scout:fts5-optimize {model?* : The searchable models to optimize index tables for}';

    protected $description = 'Merge FTS5 index segments to speed up searches after a bulk import';

    public function handle(Schema $schema): int
    {
        foreach ($this->searchableModels() as $model) {
            $table = $schema->tableFor($model);

            $schema->optimize($table);

            $this->components->twoColumnDetail($table, '<fg=green>optimized</>');
        }

        return self::SUCCESS;
    }
}
