<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Cari sayfası düğmeleri esnaf dilinde (tek sıra); tedarikçi kapalıysa (mimar) alım/ödeme yok. */
class PartyButtonsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_four_plain_language_buttons_with_supplier_side(): void
    {
        $this->actingAs(User::factory()->create());
        config(['modules.cari_supplier' => true, 'modules.direct_sales' => false]);
        $party = Party::create(['name' => 'Düğme Test Ahmet']);

        $this->get("/admin/parties/{$party->id}/edit")->assertOk()
            ->assertSeeInOrder(['Satış Yaptım', 'Ödeme Aldım', 'Alım Yaptım', 'Ödeme Yaptım'])
            ->assertDontSee('Alış / Hizmet');
    }

    public function test_only_customer_side_buttons_without_supplier(): void
    {
        $this->actingAs(User::factory()->create());
        config(['modules.cari_supplier' => false, 'modules.direct_sales' => false]);
        $party = Party::create(['name' => 'Düğme Test Mimar']);

        $this->get("/admin/parties/{$party->id}/edit")->assertOk()
            ->assertSeeInOrder(['Satış Yaptım', 'Ödeme Aldım'])
            ->assertDontSee('Alım Yaptım')
            ->assertDontSee('Ödeme Yaptım');
    }
}
