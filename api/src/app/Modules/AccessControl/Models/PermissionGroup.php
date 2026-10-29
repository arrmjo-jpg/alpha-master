<?php

namespace App\Modules\AccessControl\Models;

use App\Modules\Core\Localization\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property array<string, string> $name
 * @property array<string, string>|null $description
 * @property int $sort_order
 */
class PermissionGroup extends Model
{
    use HasTranslations;

    protected $fillable = ['key', 'name', 'description', 'sort_order'];

    protected function casts(): array
    {
        return ['name' => 'array', 'description' => 'array'];
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class);
    }
}
