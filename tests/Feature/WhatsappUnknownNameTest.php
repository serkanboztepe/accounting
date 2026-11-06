<?php

namespace Tests\Feature;

use App\Models\AssistantMemory;
use App\Models\Party;
use App\Models\Product;
use App\Services\Whatsapp\ChatAssistant;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Akkuş 06.11 "Tokgöz stok": asistan "Tokgöz listede cari olarak kayıtlı" dedi — Tokgöz kayıtlı değildi (talimattaki örnek
 * cümleden sandı). Düz cevapta "kayıtlı" denen ad (registered_names) CARİLER / ÜRÜNLER / hafızada yoksa cevap geri çevrilir;
 * yine derse sabit, doğru cevap. Gerçek AI ile denenmedi — AI mock.
 */
class WhatsappUnknownNameTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905550009993';

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('trade'), 'services.anthropic.api_key' => 'test',
            'services.anthropic.chat_model' => 'claude-sonnet-5']);
        ChatAssistant::$deadline = null;
    }

    private function reply(string $id, string $text, array $names = []): array
    {
        return ['content' => [['type' => 'tool_use', 'id' => $id, 'name' => 'reply', 'input' => ['text' => $text, 'registered_names' => $names]]], 'usage' => []];
    }

    public function test_unknown_name_claim_is_sent_back_and_corrected(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->reply('t1', 'Tokgöz listede cari olarak kayıtlı ama stok takibi olan bir ürün değil.', ['Tokgöz']))
            ->push($this->reply('t2', 'Tokgöz kayıtlı kişi değil. Tokgöz\'den aldığın yağın stoğunu mu soruyorsun, yoksa yeni kişi olarak mı ekleyeyim?')),
        ]);

        $r = app(ChatAssistant::class)->respond(self::PHONE, 'Tokgöz stok', null);

        $this->assertStringStartsWith('Tokgöz kayıtlı kişi değil.', $r['text']);
        $this->assertCount(2, Http::recorded(), 'ilk cevap kullanıcıya gitmedi, geri çevrildi');
        $second = json_encode(Http::recorded()[1][0]->data(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('\"Tokgöz\" CARİLER', $second, 'AI\'a hangi adın olmadığı söylendi');
    }

    public function test_repeated_claim_gets_fixed_answer(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->reply('t1', 'Tokgöz kayıtlı.', ['Tokgöz']))
            ->push($this->reply('t2', 'Tokgöz kayıtlı, eminim.', ['Tokgöz\'den'])),
        ]);

        $r = app(ChatAssistant::class)->respond(self::PHONE, 'Tokgöz stok', null);

        $this->assertSame(ChatAssistant::unknownNamesReply(['Tokgöz\'den']), $r['text']);
        $this->assertStringContainsString('kayıtlı bir kişi ya da ürün bulamadım', $r['text']);
    }

    /** Pozitif kontrol: kayıtlı kişi / ürün / hafızadaki ad geri çevrilmez; benzer harfli kelime ("stok") eşleşme sayılmaz. */
    public function test_known_names_pass_and_matching_is_whole_word(): void
    {
        Party::create(['name' => 'Uncu Muhyiddin Test']);
        Product::create(['name' => 'Pastacı yağ test', 'type' => Product::TYPE_PRODUCT]);
        AssistantMemory::create(['kind' => 'note', 'subject' => 'Stok tutulmuyor: Un', 'fact' => 'Un için stok tutulmuyor.', 'status' => 'active']);

        $this->assertSame([], ChatAssistant::unknownNames(['Uncu Muhyiddin Test', 'muhyiddin', 'Pastacı yağ test', "Muhyiddin'e"]));
        $this->assertSame(['Tokgözxyz'], ChatAssistant::unknownNames(['Tokgözxyz', 'Un']), 'hafızadaki "Un" bilinir; "stok" ile karışmaz');
        $this->assertSame([], ChatAssistant::unknownNames([]), 'ad yazılmadıysa kontrol yok');

        Http::fake(['api.anthropic.com/*' => Http::sequence()->push($this->reply('t1', 'Muhyiddin kayıtlı, borcun 0.', ['muhyiddin']))]);
        $this->assertSame('Muhyiddin kayıtlı, borcun 0.', app(ChatAssistant::class)->respond(self::PHONE, 'Muhyiddin hesap', null)['text']);
        $this->assertCount(1, Http::recorded());
    }
}
