<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A language the interface can be shown in. Adding a language means adding a row here and its translations.
 */
#[Fillable(['code', 'name', 'native_name', 'is_active', 'sort'])]
class Language extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Codes of the languages a user may choose, in display order.
     *
     * @return Collection<int, string>
     */
    public static function activeCodes(): Collection
    {
        return static::query()->active()->pluck('code');
    }

    /**
     * Whether the code names an active language.
     */
    public static function isActive(mixed $code): bool
    {
        return is_string($code) && static::query()->active()->whereKey($code)->exists();
    }

    /**
     * Scope the query to active languages, in display order.
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort')->orderBy('code');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
