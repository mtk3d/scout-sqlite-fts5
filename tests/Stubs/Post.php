<?php

declare(strict_types=1);

namespace ScoutFts5\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

/**
 * @property string $title
 */
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
