<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['name' => 'Adet', 'code' => 'adet'],
            ['name' => 'Ton', 'code' => 'ton'],
            ['name' => 'Kg', 'code' => 'kg'],
            ['name' => 'Metre', 'code' => 'mt'],
            ['name' => 'm2', 'code' => 'm2'],
            ['name' => 'm3', 'code' => 'm3'],
            ['name' => 'Paket', 'code' => 'paket'],
            ['name' => 'Takım', 'code' => 'takim'],
        ];

        foreach ($units as $unit) {
            Unit::query()->updateOrCreate(['code' => $unit['code']], $unit);
        }
    }
}
