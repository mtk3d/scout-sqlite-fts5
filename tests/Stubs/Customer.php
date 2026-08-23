<?php

declare(strict_types=1);

namespace Wrenchr\Scout\Fts5\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Customer extends Model
{
    use Searchable;

    protected $guarded = [];

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
            'city' => $this->city,
            'notes' => $this->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function searchableFilters(): array
    {
        return [
            'tenant_id' => $this->tenant_id,
        ];
    }
}
