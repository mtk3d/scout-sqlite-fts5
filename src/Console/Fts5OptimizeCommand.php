<?php

declare(strict_types=1);

namespace Wrenchr\Scout\Fts5\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Wrenchr\Scout\Fts5\Console\Concerns\ResolvesSearchableModels;
use Wrenchr\Scout\Fts5\Support\Fts5Schema;

#[AsCommand(name: 'scout:fts5-optimize')]
class Fts5OptimizeCommand extends Command
{
    use ResolvesSearchableModels;

    protected $signature = 'scout:fts5-optimize {model?* : The searchable models to optimize index tables for}';

    protected $description = 'Merge FTS5 index segments to speed up searches after a bulk import';

    public function handle(Fts5Schema $schema): int
    {
        foreach ($this->searchableModels() as $model) {
            $table = $schema->tableFor($model);

            $schema->optimize($table);

            $this->components->twoColumnDetail($table, '<fg=green>optimized</>');
        }

        return self::SUCCESS;
    }
}
