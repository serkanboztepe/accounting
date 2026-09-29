<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Extracts expense data from free text and/or a document photo.
 * Uses the Claude Messages API with tool-use (guaranteed structured output).
 *
 * Returns:
 *   amount (float), date (Y-m-d), description (string),
 *   project_id (int|null), party_id (int|null),
 *   category_id (int|null), category_name (string|null),
 *   paid (bool), confidence ('high'|'low'),
 *   question (string|null)  — asked back to the user when a match is missing
 */
class ExpenseExtractor
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /**
     * @param  array{media_type:string,data:string}|null  $image  base64 image
     * @param  array|null  $previous  önceki taslak — verilirse yeni mesaj DÜZELTME sayılır
     */
    public function extract(?string $text, ?array $image = null, ?array $previous = null): array
    {
        $apiKey = config('services.anthropic.api_key');
        if (empty($apiKey)) {
            throw new RuntimeException('ANTHROPIC_API_KEY tanımlı değil (.env).');
        }

        $content = [];
        if ($image !== null) {
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $image['media_type'],
                    'data' => $image['data'],
                ],
            ];
        }
        $userText = trim($text ?: '');
        if ($previous !== null) {
            $content[] = [
                'type' => 'text',
                'text' => "Kullanıcının önceki taslağa düzeltmesi/ek bilgisi:\n" . ($userText !== '' ? $userText : '(ekteki belgeye bak)'),
            ];
        } else {
            $content[] = [
                'type' => 'text',
                'text' => $userText !== ''
                    ? "Bu gideri kaydet:\n" . $userText
                    : 'Ekteki belgeden gideri çıkar ve kaydet.',
            ];
        }

        // Fotoğraf varsa güçlü vision modeli (el yazısı okuma), metinde ucuz model.
        $model = $image !== null
            ? config('services.anthropic.vision_model')
            : config('services.anthropic.model');

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(90)->post(self::ENDPOINT, [
            'model' => $model,
            'max_tokens' => 1024,
            'system' => $this->systemPrompt($previous),
            'tools' => [$this->tool()],
            'tool_choice' => ['type' => 'tool', 'name' => 'save_expense'],
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Anthropic API hatası: ' . $response->status() . ' ' . $response->body());
        }

        foreach ($response->json('content', []) as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'save_expense') {
                return $this->normalize($block['input'] ?? []);
            }
        }

        throw new RuntimeException('AI yapılandırılmış çıktı döndürmedi.');
    }

    private function systemPrompt(?array $previous = null): string
    {
        $today = now()->format('Y-m-d');
        $context = ExpenseContext::build();

        $refine = '';
        if ($previous !== null) {
            $prevJson = json_encode($previous, JSON_UNESCAPED_UNICODE);
            $refine = <<<REFINE

            ÖNEMLİ — Aşağıda ONAY BEKLEYEN önceki bir taslak var. Kullanıcının yeni mesajı ya
            (a) bu taslağa bir DÜZELTME/EK BİLGİdir ("proje Cumhuriyet", "tutar 6000", "nakit"),
            ya da (b) TAMAMEN YENİ, ayrı bir giderdir. Karar ver ve `is_new_expense`'i doldur:
            - Düzeltme/ek bilgi ise: `is_new_expense`=false; önceki değerleri KORU, yalnızca yeni
              mesajın belirttiği alanları güncelle (ör. önceki tutar 12000 ve mesaj sadece projeyi
              söylüyorsa tutarı 12000 bırak).
            - Yeni mesaj kendi başına ayrı bir gider tanımlıyorsa (kendi tutarı var, önceki gideri
              düzeltmiyor): `is_new_expense`=true; önceki taslağı YOK SAY, gideri SIFIRDAN çıkar —
              önceki proje/cari/kategori/tutarı ASLA taşıma.

            ÖNCEKİ TASLAK: {$prevJson}

            REFINE;
        }

        return <<<PROMPT
        Sen bir inşaat şirketinin gider kayıt asistanısın. Müteahhit sana bir gideri
        yazarak veya belge (fiş, fatura, dekont) fotoğrafı atarak bildirir. Görevin
        `save_expense` aracını çağırarak gider bilgisini çıkarmak.

        Bugünün tarihi: {$today}. Tarih belirtilmemişse `date` alanına bugünü koy.
        {$refine}
        PROJE ve CARİ için: önce aşağıdaki mevcut listelerle eşleştir (`project_id` / `party_id`).
        El yazısı bir isim, mevcut cari listesindeki bir isme makul ölçüde yakınsa (özellikle
        ilk ad tutuyorsa), harf harf yeniden okumaya çalışma — o mevcut cariyle EŞLEŞTİR
        (`party_id`). Tanıma, kör okumadan güvenilirdir.
        Kullanıcı bir proje/cari adı söyledi ama listede yoksa, id'yi null bırak ve adı
        `project_name` / `party_name` alanına yaz — onay verilince yeni kayıt açılacak.
        Kullanıcı hiç proje/cari söylemediyse hepsini null bırak (zorunlu değil).
        ÖNEMLİ: Sistemde tek proje/cari olsa bile, mesaj veya belge o projeyi/cariyi AÇIKÇA
        belirtmiyorsa proje/cari'yi null bırak — "tek seçenek bu" diye otomatik atama YAPMA
        (yanlış maliyet atfı olur). Özellikle bir çek belgesinde proje bilgisi YOKTUR;
        kullanıcı söylemediyse `project_id`'yi null bırak.
        `question` alanını proje/cari eklemek için KULLANMA — sadece tutarı gerçekten
        okuyamıyorsan gibi kritik bir belirsizlikte kullan.

        KATEGORİ için: kategori GENİŞ bir gider TÜRÜdür (Malzeme, İşçilik, Nakliye…), tek tek
        malzeme veya marka adı DEĞİLDİR. Önce mevcut kategori listesiyle eşleştir (`category_id`)
        ve mümkün olan en GENİŞ olanı tercih et. Somut yapı malzemeleri — boya, çimento, demir,
        kum, çakıl, tuğla, alçı, seramik, fayans, kereste, kablo, boru, vida, hırdavat vb. — hepsi
        "Malzeme" kategorisine girer; bunlar için AYRI kategori açma (mevcut "Malzeme" varsa onu seç).
        Sadece mevcut hiçbir geniş türe girmeyen, gerçekten YENİ bir gider türü varsa `category_name`'e
        kısa bir ad öner; aksi halde uygun mevcut kategoriyle eşleştir ve `category_name`'i boş bırak.
        Kararsızsan yeni açmaktansa en yakın mevcut kategoriyi seç — mükerrer/aşırı ince kategori açma.

        BELGE ÇEK İSE: Çekte iki taraf vardır — (a) matbu basılı KEŞİDECİ / hesap sahibi
        firma (çeki YAZAN, genelde bizim kendi şirketimizdir) ve (b) el yazısıyla yazılan
        LEHTAR / alıcı ("… emrine ödeyiniz" satırına EL YAZISIYLA yazılmış AD-SOYAD/isim,
        paranın GİTTİĞİ taraf). Cari (party) = bu el yazısı ad-soyad'dır; matbu basılı
        keşideci firmayı (Boztepeler gibi) cari YAPMA. Tutarı çekteki rakam/yazı tutarından,
        tarihi çekin (vade) tarihinden al.
        Alıcı adı TÜRKÇE bir ad-soyad'dır. Türk alfabesinde X, Q, W harfleri YOKTUR ve
        Türkçe isimler bu harfleri içermez. Okuduğun ad-soyad'da X/Q/W çıkıyorsa KESİNLİKLE
        yanlış okumuşsundur — en yakın Türkçe harfe düzelt (harfleri yeniden değerlendir),
        çıktıda asla X/Q/W kullanma. Türkçe harfler: a b c ç d e f g ğ h ı i j k l m n o ö
        p r s ş t u ü v y z.
        El yazısı ad-soyad'ı NET okuyamıyorsan UYDURMA: `party_name`'i null bırak,
        `confidence`'i "low" yap ve `question`'a "Çekteki alıcının adını net okuyamadım,
        kim olduğunu yazar mısın?" yaz. Yanlış/saçma bir isim yazmaktansa sormak daha iyi.

        Tutarı (`amount`) Türk Lirası olarak, sayı biçiminde döndür (binlik ayraç/simge yok).
        Ödendiyse `paid` = true. El yazısı/bulanık nedeniyle tutardan emin değilsen
        `confidence` = "low" yap.

        {$context}
        PROMPT;
    }

    private function tool(): array
    {
        return [
            'name' => 'save_expense',
            'description' => 'Çıkarılan gider bilgisini yapılandırılmış olarak döndürür.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'amount' => ['type' => 'number', 'description' => 'Expense amount (TRY, numeric)'],
                    'date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD; today if absent'],
                    'description' => ['type' => 'string', 'description' => 'What the expense is for (short)'],
                    'project_id' => ['type' => ['integer', 'null'], 'description' => 'Matched existing project id or null'],
                    'project_name' => ['type' => ['string', 'null'], 'description' => 'Proposed NEW project name if the user named one that is not in the list; null if matched or none named'],
                    'party_id' => ['type' => ['integer', 'null'], 'description' => 'Matched existing party id or null'],
                    'party_name' => ['type' => ['string', 'null'], 'description' => 'Proposed NEW party name if the user named one that is not in the list; null if matched or none named'],
                    'category_id' => ['type' => ['integer', 'null'], 'description' => 'Matched existing category id or null'],
                    'category_name' => ['type' => ['string', 'null'], 'description' => 'Proposed NEW short category name if none fits; null if an existing category matched'],
                    'paid' => ['type' => 'boolean', 'description' => 'Paid already? true if unclear'],
                    'confidence' => ['type' => 'string', 'enum' => ['high', 'low']],
                    'question' => ['type' => ['string', 'null'], 'description' => 'Question to ask the user for a missing project/party match, else null'],
                    'is_new_expense' => ['type' => 'boolean', 'description' => 'Only meaningful when a previous draft is provided: true if the new message is a brand-new separate expense (extract from scratch, ignore previous), false if it refines the previous draft. Default false.'],
                ],
                'required' => ['amount', 'description', 'paid', 'confidence'],
            ],
        ];
    }

    private function normalize(array $input): array
    {
        return [
            'amount' => isset($input['amount']) ? (float) $input['amount'] : 0.0,
            'date' => $input['date'] ?? now()->format('Y-m-d'),
            'description' => (string) ($input['description'] ?? ''),
            'project_id' => isset($input['project_id']) ? (int) $input['project_id'] : null,
            'project_name' => ! empty($input['project_name']) ? trim((string) $input['project_name']) : null,
            'party_id' => isset($input['party_id']) ? (int) $input['party_id'] : null,
            'party_name' => ! empty($input['party_name']) ? trim((string) $input['party_name']) : null,
            'category_id' => isset($input['category_id']) ? (int) $input['category_id'] : null,
            'category_name' => ! empty($input['category_name']) ? trim((string) $input['category_name']) : null,
            'paid' => (bool) ($input['paid'] ?? true),
            'confidence' => in_array($input['confidence'] ?? 'high', ['high', 'low'], true) ? $input['confidence'] : 'high',
            'question' => ! empty($input['question']) ? (string) $input['question'] : null,
            'is_new_expense' => (bool) ($input['is_new_expense'] ?? false),
        ];
    }
}
