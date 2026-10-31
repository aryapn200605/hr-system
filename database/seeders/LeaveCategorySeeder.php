<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LeaveCategorySeeder extends Seeder
{
    /**
     * The base categories. Add or change one here: there is no screen for them.
     */
    private const CATEGORIES = [
        ['code' => 'annual', 'name' => 'Annual leave', 'deducts_balance' => true],
        ['code' => 'sick', 'name' => 'Sick leave', 'deducts_balance' => false],
        ['code' => 'other', 'name' => 'Other leave', 'deducts_balance' => false],
    ];

    /**
     * Seed the categories. Safe to run repeatedly, and it leaves is_active as it finds it,
     * so a category a developer switched off stays off.
     */
    public function run(): void
    {
        foreach (self::CATEGORIES as $category) {
            DB::table('leave_categories')->updateOrInsert(
                ['code' => $category['code']],
                ['name' => $category['name'], 'deducts_balance' => $category['deducts_balance'], 'updated_at' => now()],
            );
        }
    }
}
