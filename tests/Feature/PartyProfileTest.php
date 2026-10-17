<?php

namespace Tests\Feature;

use App\Models\AssistantMemory;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\PartyProfileSuggestion;
use App\Services\Whatsapp\ExpenseContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Faz 3.2: cari tanımı önerisi → onaysız kullanılmaz; onaylanınca asistan hafızasında. */
class PartyProfileTest extends TestCase
{
    use DatabaseTransactions;

    public function test_suggestion_is_used_only_after_approval(): void
    {
        config(['services.anthropic.api_key' => 'sk-test']);
        AssistantMemory::query()->update(['status' => 'deleted']);
        PartyProfileSuggestion::query()->delete();
        PartyLedgerEntry::query()->update(['entry_date' => '2000-01-01']); // yerel eski hareketler aday olmasın (geri alınır)
        $sinan = Party::create(['name' => 'Tanım Testi Sinan']);
        foreach ([1, 2, 3] as $i) {
            PartyLedgerEntry::create(['party_id' => $sinan->id, 'type' => PartyLedgerEntry::TYPE_PAYMENT, 'amount' => 100000,
                'entry_date' => now()->subDays($i)->toDateString(), 'description' => 'Avans — beton bağlantısı', 'payment_type' => 'check']);
        }
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'tool_use', 'name' => 'profiles',
            'input' => ['items' => [['party_id' => $sinan->id, 'profile' => 'Malzemeci — beton bağlantısı var, çekle ödeniyor.']]]]], 'usage' => []])]);

        $this->artisan('parties:suggest-profiles')->assertSuccessful();

        $s = PartyProfileSuggestion::where('party_id', $sinan->id)->sole();
        $this->assertSame('pending', $s->status);
        $this->assertStringNotContainsString('beton bağlantısı var', ExpenseContext::build(), 'onaysız asistana girmez');

        $s->approve();
        $this->assertStringContainsString('Tanım Testi Sinan: Malzemeci — beton bağlantısı var', ExpenseContext::build());

        $this->artisan('parties:suggest-profiles')->assertSuccessful();
        $this->assertSame(1, PartyProfileSuggestion::where('party_id', $sinan->id)->count(), 'tanımı olan cariye yeniden önerilmez');
    }
}
