<?php

namespace App\Services\Whatsapp;

use App\Console\Commands\TenantsRun;
use App\Models\HubFirm;
use App\Models\HubPhone;
use App\Models\Party;
use App\Models\WhatsappMessage;
use App\Support\Phone;
use App\Tenancy\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Günlük konuşma analizi: bir günün tüm WhatsApp konuşmaları (her firma kendi veritabanından) →
 * Claude → numaralı rapor için metin. Amaç: asistanın takıldığı, yanlış anladığı, kullanıcının
 * isteyip yapamadığı yerleri görmek ve önerileri yöneticiye sormak (kod değiştirmez).
 *
 * Gizlilik: carilerin (müşterinin müşterisi) adları Claude'a gitmeden "Cari-1" gibi değiştirilir;
 * rapordaki tutarlar sonradan "[tutar]" yapılır. Firma ve kullanıcı (bizim müşterimiz) adı görünür.
 */
class ConversationAnalyzer
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const KIND_LABELS = [
        'expense' => 'gider kaydı', 'payment' => 'ödeme (biz verdik)', 'sale' => 'satış', 'collection' => 'tahsilat',
        'balance_query' => 'bakiye sorusu', 'totals_query' => 'toplam alacak/borç sorusu', 'statement' => 'ekstre PDF',
        'expense_summary' => 'dönem gider özeti', 'debt_note' => 'alacak/borç kaydı', 'party_list' => 'cari listesi',
        'help' => 'anlaşılamadı → yönlendirme',
    ];

    /**
     * @return array{transcript:string,message_count:int,firm_count:int}
     */
    public function collect(CarbonInterface $day): array
    {
        $blocks = [];
        $messages = 0;

        foreach ($this->firms() as $firm) {
            $block = $firm
                ? Tenancy::run($firm, fn () => $this->firmBlock($firm, $day))
                : $this->firmBlock(null, $day);

            if ($block !== null) {
                $blocks[] = $block['text'];
                $messages += $block['count'];
            }
        }

        return ['transcript' => implode("\n\n", $blocks), 'message_count' => $messages, 'firm_count' => count($blocks)];
    }

    /**
     * Claude'dan rapor. İlk satır "ÖZET: …" (WhatsApp özeti), kalanı markdown rapor.
     *
     * @return array{summary:string,body:string,model:string,cost_usd:float}
     */
    public function analyze(string $transcript, CarbonInterface $day): array
    {
        $apiKey = (string) config('services.anthropic.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY tanımlı değil (.env).');
        }
        $model = (string) config('services.anthropic.analysis_model');

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(300)->post(self::ENDPOINT, [
            'model' => $model,
            'max_tokens' => 6000,
            'system' => $this->systemPrompt(),
            'messages' => [['role' => 'user', 'content' => 'Tarih: ' . $day->format('d.m.Y') . "\n\n" . $transcript]],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Anthropic API hatası: ' . $response->status() . ' ' . $response->body());
        }

        $text = collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode("\n");
        [$in, $out] = config("costs.ai_per_mtok.{$model}", [0, 0]);
        $usage = (array) $response->json('usage', []);
        $cost = (($usage['input_tokens'] ?? 0) * $in + ($usage['output_tokens'] ?? 0) * $out) / 1_000_000;

        $summary = '';
        if (preg_match('/^\s*ÖZET:\s*(.+)$/mu', $text, $m)) {
            $summary = trim($m[1]);
            $text = trim(preg_replace('/^\s*ÖZET:.*$/mu', '', $text, 1) ?? $text);
        }

        return [
            'summary' => self::maskAmounts(mb_substr($summary ?: 'Rapor hazır.', 0, 300)),
            'body' => self::maskAmounts($text),
            'model' => $model,
            'cost_usd' => round($cost, 6),
        ];
    }

    /**
     * Tutarları gizle: "20.000", "1.250,50", "20000" → [tutar]. Yıl (2026), saat (21:36), tarih
     * (2026-10-10, 10.10) ve küçük sayılar (47, 3) kalır — raporun okunması için gerekli.
     */
    public static function maskAmounts(string $text): string
    {
        $text = preg_replace('/(?<![\d.,:\-])\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?(?![\d\-])/u', '[tutar]', $text) ?? $text;

        return preg_replace('/(?<![\d.,:\-])(?!(?:19|20)\d{2}(?!\d))\d{4,}(?:,\d{1,2})?(?![\d.\-:])/u', '[tutar]', $text) ?? $text;
    }

    /** @return list<HubFirm|null> null = tek kurulum (tenancy kapalı: mevcut veritabanı) */
    private function firms(): array
    {
        return Tenancy::enabled() ? TenantsRun::firms()->all() : [null];
    }

    /** @return array{text:string,count:int}|null o gün mesaj yoksa null */
    private function firmBlock(?HubFirm $firm, CarbonInterface $day): ?array
    {
        $messages = WhatsappMessage::query()
            ->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            return null;
        }

        $mask = $this->partyMask();
        $names = $firm ? HubPhone::where('hub_firm_id', $firm->id)->pluck('name', 'phone') : collect();
        $kinds = implode(', ', array_map(fn ($k) => self::KIND_LABELS[$k] ?? $k, ExpenseExtractor::allowedKinds()));

        $lines = ['### Firma: ' . ($firm?->name ?? config('app.name')) . ' (sektör: ' . (config('app.profile') ?: 'hepsi açık') . ')',
            'Bu firmada asistanın yapabildikleri: ' . $kinds . (config('modules.checks') ? ', "çekler" listesi' : '') . ', "nasıl" rehberi.'];

        foreach ($messages->groupBy('phone') as $phone => $rows) {
            $lines[] = '';
            $lines[] = '#### Kullanıcı: ' . ($names[$phone] ?? Phone::display($phone));
            foreach ($rows as $m) {
                $body = strtr((string) $m->body, $mask);
                $lines[] = $m->created_at->format('H:i') . ($m->direction === 'in'
                    ? ' >> KULLANICI [' . (self::KIND_LABELS[$m->kind] ?? $m->kind ?? '?') . ']' . ($m->has_media ? ' [fotoğraf]' : '') . ': ' . $body
                    : ' << ASİSTAN' . ($m->has_media ? ' [PDF eki gönderildi]' : '') . ': ' . mb_substr(str_replace("\n", ' / ', $body), 0, 600));
            }
        }

        return ['text' => implode("\n", $lines), 'count' => $messages->count()];
    }

    /** Firma adlarında sık geçen genel kelimeler — ilk kelime olarak maskelenmez ("Beton aldım" bozulmasın). */
    private const GENERIC_WORDS = ['beton', 'inşaat', 'insaat', 'yapı', 'yapi', 'ticaret', 'nakliyat', 'nakliye', 'hırdavat',
        'elektrik', 'tesisat', 'mimarlık', 'mühendislik', 'usta', 'market', 'gıda', 'otomotiv', 'tarım', 'emlak', 'sigorta'];

    /**
     * Cari adı → "Cari-N". Kullanıcılar çoğu zaman yalnız ilk adı yazar ("Tuncay'ın ekstresi") —
     * ilk kelime de maskelenir, yoksa analist "Tuncay" ile "Cari-1"i farklı kişi sanıyordu.
     * Uzun ifadeler önce değiştirilir ("Ali" "Ali Yılmaz"ın içini bozmasın).
     */
    private function partyMask(): array
    {
        $map = [];
        foreach (Party::query()->orderBy('id')->pluck('name') as $i => $name) {
            $name = trim($name);
            $label = 'Cari-' . ($i + 1);
            if (mb_strlen($name) >= 3) {
                $map[$name] = $label;
            }
            $first = trim((string) strtok($name, ' '));
            $firstLower = mb_strtolower(strtr($first, ['İ' => 'i', 'I' => 'ı']));
            if ($first !== $name && mb_strlen($first) >= 3 && ! in_array($firstLower, self::GENERIC_WORDS, true)) {
                $map[$first] ??= $label;
            }
        }
        uksort($map, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $map;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        Sen "Hesap Asistanım" ürününün kalite analistisin. Ürün: esnafın (müteahhit, mimar, toptancı,
        alacak-verecek defteri tutan küçük işletme) WhatsApp'tan yazarak gider, ödeme, tahsilat, satış,
        alacak/borç kaydı tuttuğu ve bakiye/ekstre sorduğu bir asistan. Asistan her kaydı yazmadan önce
        özet gösterip "evet" ister. Sana bir günün tüm konuşmaları veriliyor (firma firma, kullanıcı kullanıcı;
        köşeli parantezde asistanın mesajı nasıl sınıflandırdığı).

        Görevin: ürün sahibine (Serkan) asistanı geliştirmek için sade Türkçe, esnaf diliyle bir rapor yazmak.
        Teknik terim kullanma (tablo, kod, model, prompt deme). Kısa ve somut ol; mesajlardan kısa alıntı yap.

        ÇIKTI BİÇİMİ (tam olarak):
        İlk satır: "ÖZET: " ile başlayan tek satır (en fazla 160 karakter) — kaç takılma, kaç öneri, en önemlisi ne.
        Sonra şu başlıklar (boşsa "Yok." yaz):

        ## Genel durum
        2-3 cümle: kim ne kadar kullandı, akış genel olarak iyi mi.

        ## Takılmalar
        Kullanıcının istediğini yapamadığı / tekrar tekrar denediği / vazgeçtiği yerler. Her biri:
        **T1. [Firma — kullanıcı]** ne oldu (kısa alıntı) → neden takıldı.

        ## Yanlış anlaşılmış olabilecek kayıtlar
        Onaylanıp kaydedilmiş ama yanlış olabilecekler (yön ters, çift kayıt, yanlış tür, yanlış kişi).
        **Y1. [Firma — kullanıcı]** … → kullanıcıya sorulması gereken.
        Kullanıcının verisini DÜZELTMEYİ ÖNERME; sadece "kullanıcıya sorulabilir" de.

        ## İstenen ama olmayan şeyler
        Kullanıcıların yapmak isteyip asistanın desteklemediği işler.

        ## Öneriler
        Numaralı (1, 2, 3…). Her öneri:
        **1. Başlık** — ne değişsin (kullanıcının göreceği davranış olarak), hangi takılmayı çözer (T1, Y2…).
        *Sana soru:* ürün sahibinin karar vermesi gereken nokta (örn. "sorsun mu, varsayılan mı olsun?").
        En önemli öneri en üstte; en fazla 6 öneri. Önemsiz kozmetik şeyleri yazma.

        KURALLAR:
        - "Cari-3" gibi adlar gizlenmiş kişi/firma adlarıdır; olduğu gibi kullan, tahmin etme.
        - Tutar yazma (rakamları raporda tekrar etme); "yüksek tutarlı", "kira tutarı" gibi söyle.
        - Uydurma: konuşmada olmayan bir şeyi olmuş gibi yazma. Emin değilsen "olabilir" de.
        - Asistanın doğru yaptığı şeyleri öneri diye yazma; sadece gerçekten iyileşecek yerler.
        PROMPT;
    }
}
