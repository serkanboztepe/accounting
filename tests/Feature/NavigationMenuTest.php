<?php

namespace Tests\Feature;

use App\Filament\Pages\CompanySettingsPage;
use App\Filament\Resources\ExpenseCategories\ExpenseCategoryResource;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Sol menü (kullanıcı 21.10): eski "Sistem" sayfaları (Ayarlar, Birimler, Gider Kategorileri, Asistan hafızası) sol menüde değil,
 * sağ üstteki profil menüsünde; gruplar açılır-kapanır değil.
 */
class NavigationMenuTest extends TestCase
{
    use DatabaseTransactions;

    public function test_system_pages_live_in_user_menu_and_groups_do_not_collapse(): void
    {
        config(['modules.expenses' => true]);
        $this->actingAs(User::factory()->create());

        $html = $this->get('/admin/calendar')->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<nav[^>]*fi-sidebar-nav".*?</nav>#s', $html, $m), 'sol menü bulunamadı');
        $sidebar = $m[0];
        $outside = str_replace($sidebar, '', $html);

        foreach ([CompanySettingsPage::getUrl(), ExpenseCategoryResource::getUrl('index')] as $url) {
            $this->assertStringNotContainsString('href="' . $url . '"', $sidebar, "$url sol menüde olmamalı");
            $this->assertStringContainsString('href="' . $url . '"', $outside, "$url profil menüsünde olmalı");
        }
        $this->assertStringContainsString('Gider Kategorileri', $outside);
        $this->assertStringNotContainsString('fi-collapsible', $sidebar);
        $this->assertStringContainsString('fi-sidebar-group-label', $sidebar, 'grup başlıkları duruyor');

        // Sayfa aynı adreste açılıyor.
        $this->get(ExpenseCategoryResource::getUrl('index'))->assertOk();
    }
}
