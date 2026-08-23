<?php

declare(strict_types=1);

namespace Mtk3d\Scout\Fts5\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Post extends Model
{
    use Searchable, SoftDeletes;

    protected $guarded = [];

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return ['title' => $this->title];
    }
}
