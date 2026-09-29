<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomType extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'category', 'default_capacity',
        'has_ac', 'description', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'has_ac' => 'boolean',
            'is_active' => 'boolean',
            'default_capacity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function tariffs(): HasMany
    {
        return $this->hasMany(Tariff::class);
    }

    public function isVip(): bool
    {
        return $this->category === 'VIP';
    }

    /**
     * Display name including the category, as the availability screen shows it:
     * "Deluxe AC (VIP)".
     */
    public function displayName(): string
    {
        return $this->isVip() ? "{$this->name} (VIP)" : $this->name;
    }
}
