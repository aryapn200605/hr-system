<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The leave categories (DECISIONS.md section 4). Developers change them here and run the seeder again:
 * it inserts missing codes and updates the others, and never deletes one. Requests freeze whether they
 * deduct balance, so changing a category does not alter requests already made.
 */
class LeaveCategorySeeder extends Seeder
{
    /**
     * The names are English translation keys, listed in App\Translation\DynamicLabels.
     */
    public const CATEGORIES = [
        ['code' => 'annual', 'name' => 'Annual leave', 'deducts_balance' => true, 'is_active' => true],
        ['code' => 'sick', 'name' => 'Sick leave', 'deducts_balance' => false, 'is_active' => true],
        ['code' => 'other', 'name' => 'Other leave', 'deducts_balance' => false, 'is_active' => true],
    ];

    public function run(): void
    {
        $now = now();

        DB::table('leave_categories')->upsert(
            array_map(fn (array $row) => $row + ['created_at' => $now, 'updated_at' => $now], self::CATEGORIES),
            ['code'],
            ['name', 'deducts_balance', 'is_active', 'updated_at'],
        );
    }
}
