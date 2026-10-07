<?php

namespace Tests\Feature;

use App\Filament\Pages\CariUnlock;
use App\Filament\Resources\Parties\PartyResource;
use App\Models\Party;
use App\Models\User;
use App\Filament\Pages\CompanySettingsPage;
use App\Support\CariLock;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class CariLockTest extends TestCase
{
    use DatabaseTransactions;

    private const KEY = 'cari_lock.last_activity';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.cari_lock' => true, 'app.cari_lock_minutes' => 5]);
    }

    public function test_disabled_lock_lets_cariler_open(): void
    {
        config(['app.cari_lock' => false]);

        $this->actingAs(User::factory()->create())
            ->get('/admin/parties')
            ->assertOk();
    }

    public function test_locked_cariler_redirects_to_password_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/parties')
            ->assertRedirectContains('/admin/cari-kilidi');
    }

    public function test_recent_activity_keeps_cariler_open(): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession([self::KEY => now()->subMinutes(4)->timestamp])
            ->get('/admin/parties')
            ->assertOk();
    }

    public function test_idle_longer_than_limit_locks_again(): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession([self::KEY => now()->subMinutes(6)->timestamp])
            ->get('/admin/parties')
            ->assertRedirectContains('/admin/cari-kilidi');
    }

    public function test_statement_print_is_locked_too(): void
    {
        $party = Party::create(['name' => 'Kilit Test Cari']);

        $this->actingAs(User::factory()->create())
            ->get(route('party.statement.print', $party))
            ->assertRedirectContains('/admin/cari-kilidi');
    }

    public function test_wrong_password_keeps_locked_and_correct_one_unlocks(): void
    {
        $this->actingAs(User::factory()->create(['password' => 'dogru-sifre']));

        Livewire::test(CariUnlock::class)
            ->set('secret', 'yanlis')
            ->call('unlock')
            ->assertHasErrors('secret');
        $this->assertFalse(CariLock::isOpen());

        Livewire::test(CariUnlock::class)
            ->set('secret', 'dogru-sifre')
            ->call('unlock')
            ->assertRedirect(PartyResource::getUrl('index'));
        $this->assertTrue(CariLock::isOpen());
    }

    public function test_unlock_never_redirects_off_site(): void
    {
        $this->actingAs(User::factory()->create(['password' => 'dogru-sifre']));

        Livewire::withQueryParams(['redirect' => 'https://kotu-site.example/x'])
            ->test(CariUnlock::class)
            ->set('secret', 'dogru-sifre')
            ->call('unlock')
            ->assertRedirect(PartyResource::getUrl('index'));
    }

    public function test_global_search_hides_cariler_while_locked(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertFalse(PartyResource::canGloballySearch());

        CariLock::touch();
        $this->assertTrue(PartyResource::canGloballySearch());
    }

    public function test_pin_replaces_login_password_when_set(): void
    {
        $this->actingAs(User::factory()->create(['password' => 'dogru-sifre']));
        CariLock::setPin('4826');

        Livewire::test(CariUnlock::class)
            ->set('secret', 'dogru-sifre')
            ->call('unlock')
            ->assertHasErrors('secret');
        $this->assertFalse(CariLock::isOpen());

        Livewire::test(CariUnlock::class)
            ->set('secret', '4826')
            ->call('unlock')
            ->assertHasNoErrors();
        $this->assertTrue(CariLock::isOpen());
    }

    public function test_login_opens_cariler_only_without_pin(): void
    {
        $user = User::factory()->create();

        event(new Login('web', $user, false));
        $this->assertTrue(CariLock::isOpen());

        CariLock::lock();
        CariLock::setPin('4826');
        event(new Login('web', $user, false));
        $this->assertFalse(CariLock::isOpen());
    }

    public function test_too_many_wrong_pins_blocks_even_the_right_one(): void
    {
        $this->actingAs(User::factory()->create());
        CariLock::setPin('4826');

        foreach (range(1, 5) as $i) {
            $this->assertFalse(CariLock::attempt('0000'));
        }

        $this->assertFalse(CariLock::attempt('4826'));
        $this->assertStringContainsString('Çok fazla yanlış deneme', CariLock::lastAttemptMessage());
    }

    public function test_changing_pin_requires_current_pin(): void
    {
        $this->actingAs(User::factory()->create());
        CariLock::setPin('4826');

        Livewire::test(CompanySettingsPage::class)
            ->callAction('cariPin', ['current_pin' => '1111', 'pin' => '5555', 'pin_confirmation' => '5555'])
            ->assertHasActionErrors(['current_pin']);
        $this->assertTrue(CariLock::checkPin('4826'));

        Livewire::test(CompanySettingsPage::class)
            ->callAction('cariPin', ['current_pin' => '4826', 'pin' => '5555', 'pin_confirmation' => '5555'])
            ->assertHasNoActionErrors();
        $this->assertTrue(CariLock::checkPin('5555'));
    }

    public function test_pin_must_be_4_to_6_digits(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CompanySettingsPage::class)
            ->callAction('cariPin', ['pin' => '12', 'pin_confirmation' => '12'])
            ->assertHasActionErrors(['pin']);
        $this->assertFalse(CariLock::hasPin());
    }
}
