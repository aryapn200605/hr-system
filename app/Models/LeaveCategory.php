<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of leave from the reference table, kept by Database\Seeders\LeaveCategorySeeder.
 */
#[Fillable(['code', 'name', 'deducts_balance', 'is_active'])]
class LeaveCategory extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deducts_balance' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The name in the user's language. The names are registered in App\Translation\DynamicLabels.
     */
    public function label(): string
    {
        return __($this->name);
    }
}
