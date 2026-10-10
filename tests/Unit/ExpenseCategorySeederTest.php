<?php

namespace Tests\Unit;

use Database\Seeders\ExpenseCategorySeeder;
use PHPUnit\Framework\TestCase;

/** Yeni firmanın başlangıç kategorileri sektöre göre: inşaat listesi (Vinç, Ruhsat…) yalnız inşaat firmalarına. */
class ExpenseCategorySeederTest extends TestCase
{
    public function test_construction_profiles_get_construction_categories(): void
    {
        foreach (['muteahhit', 'mimar', null] as $profile) {
            $this->assertContains('Vinç', ExpenseCategorySeeder::forProfile($profile));
            $this->assertContains('Nakliye', ExpenseCategorySeeder::forProfile($profile));
        }
    }

    public function test_other_profiles_start_with_general_categories(): void
    {
        foreach (['toptanci', 'alacak_verecek'] as $profile) {
            $categories = ExpenseCategorySeeder::forProfile($profile);
            $this->assertNotContains('Vinç', $categories);
            $this->assertNotContains('Nakliye', $categories);
            $this->assertContains('Ulaşım', $categories);
            $this->assertContains('Kira', $categories);
        }
    }
}
