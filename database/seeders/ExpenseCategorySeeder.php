<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Nakliye',
            'Vinç',
            'Ruhsat',
            'Muhasebe',
            'Vergi',
            'Sigorta',
            'Elektrik',
            'Su',
        ];

        foreach ($categories as $name) {
            ExpenseCategory::query()->firstOrCreate(['name' => $name]);
        }
    }
}
