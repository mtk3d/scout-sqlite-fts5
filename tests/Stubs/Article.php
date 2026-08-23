<?php

declare(strict_types=1);

namespace Mtk3d\Scout\Fts5\Tests\Stubs;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

/**
 * A model with a string key, which is stored in an explicit `doc_id` column
 * rather than the index table's `rowid`.
 */
class Article extends Model
{
    use HasUlids, Searchable;

    protected $guarded = [];

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return ['title' => $this->title];
    }
}
