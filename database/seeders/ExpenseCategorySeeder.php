<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Nakliye', 'type' => 'both'],
            ['name' => 'Vinç', 'type' => 'project'],
            ['name' => 'Ruhsat', 'type' => 'project'],
            ['name' => 'Muhasebe', 'type' => 'general'],
            ['name' => 'Vergi', 'type' => 'general'],
            ['name' => 'Sigorta', 'type' => 'both'],
            ['name' => 'Elektrik', 'type' => 'both'],
            ['name' => 'Su', 'type' => 'both'],
        ];

        foreach ($categories as $category) {
            ExpenseCategory::query()->updateOrCreate(
                ['name' => $category['name']],
                $category,
            );
        }
    }
}
