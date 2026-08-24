<?php

declare(strict_types=1);

namespace ScoutFts5\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

/**
 * @property string $name
 * @property string|null $city
 * @property string|null $notes
 * @property int $tenant_id
 * @property string $status
 */
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
