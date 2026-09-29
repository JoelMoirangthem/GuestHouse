<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\RoleSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'name', 'description', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The typed enum case for this role, or null if an admin has created a
     * custom role that carries no workflow meaning.
     */
    public function slugEnum(): ?RoleSlug
    {
        return RoleSlug::tryFrom($this->slug);
    }

    /**
     * Named isSlug() rather than is() because Eloquent's Model::is($model)
     * already exists for comparing model identity; overriding it breaks the
     * signature contract.
     */
    public function isSlug(RoleSlug $slug): bool
    {
        return $this->slug === $slug->value;
    }
}
