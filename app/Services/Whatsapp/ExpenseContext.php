<?php

namespace App\Services\Whatsapp;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;

/**
 * AI'a verilecek eşleştirme bağlamı: mevcut projeler, cariler, gider kategorileri.
 * AI körlemesine tahmin etmesin — bu listeden id ile eşleştirsin.
 */
class ExpenseContext
{
    /**
     * "Biz" adları: firma adı + firmanın aktif numara sahipleri (+ yazan numaranın sahibi). Dekontta bu adlar
     * cari DEĞİLDİR (Ferhat, 15 Ekim: alıcı "FERHAT YILDIZ" kendisiydi, cari sanıldı). Tek kelimelik adlar
     * alınmaz ("Çiftlik" gibi genel adlar başka carilerle karışmasın). Yalnız okuma.
     *
     * @return list<string>
     */
    public static function selfNames(?string $phone = null): array
    {
        $names = [];
        if (($firm = \App\Tenancy\Tenancy::current()) !== null) {
            $names[] = (string) $firm->name;
            array_push($names, ...\App\Models\HubPhone::where('hub_firm_id', $firm->id)->where('is_active', true)->pluck('name')->all());
        }
        if ($phone !== null && $phone !== '') {
            $names[] = (string) \App\Models\HubPhone::where('phone', \App\Support\Phone::normalize($phone))->value('name');
        }

        return array_values(array_unique(array_filter(array_map('trim', $names),
            fn ($n) => $n !== '' && count(PartyNameCheck::words($n)) >= 2)));
    }

    /** Yalnız deneme betikleri: başka firmanın listesiyle AI'ı veritabanına yazmadan denemek için. Canlıda hep null. */
    public static ?string $override = null;

    /**
     * Yalnız deneme seti (tests/Feature/Eval): listeler bu kayıtlarla sınırlanır — yerel deneme DB'sindeki eski kayıtlar
     * AI'ın listesine karışmasın; biçim canlıdakiyle birebir aynı kalır. Canlıda hep null.
     *
     * @var array{parties?:list<int>,projects?:list<int>,categories?:list<int>,products?:list<int>,expenses?:list<int>}|null
     */
    public static ?array $only = null;

    private static function scoped($query, string $key)
    {
        if (self::$only === null) {
            return $query;
        }
        // Denemede konuşma sırasında açılan kayıtlar da (yeni cari / proje) listede görünsün — canlıdaki gibi.
        $after = self::$only['_after'][$key] ?? null;

        return $query->where(fn ($q) => $q->whereIn('id', self::$only[$key] ?? [])
            ->when($after !== null, fn ($w) => $w->orWhere('id', '>', $after)));
    }

    /**
     * Sistem prompt'una gömülecek kompakt liste bloğu.
     */
    public static function build(?string $phone = null): string
    {
        if (self::$override !== null) {
            return self::$override;
        }

        $projects = ! \App\Support\Features::places() ? collect() : self::scoped(Project::query(), 'projects')
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('name')
            ->get(['id', 'name', 'cadastral_parcel']);

        $parties = self::scoped(Party::query(), 'parties')->orderBy('name')->get(['id', 'name', 'kind', 'is_employee', 'salary_amount', 'salary_day']);

        $categories = self::scoped(ExpenseCategory::query(), 'categories')->orderBy('name')->get(['id', 'name']);

        $lines = [];
        // İşletme türü + notu (Faz D): asistan karşısındakinin işini bilsin — kayıt türünü doğru anlamak için.
        $sector = config('sectors.' . config('app.sector'));
        if ($sector || config('app.business_note')) {
            $lines[] = 'İŞLETME: ' . trim(($sector['label'] ?? '') . (config('app.business_note') ? ' — ' . config('app.business_note') : ''), ' —');
            if (! empty($sector['knowledge'])) {
                $lines[] = '  İş bilgisi: ' . $sector['knowledge'];
            }
            $lines[] = '';
        }
        // İşletme hafızası (Faz 2): kullanıcının öğrettikleri — en üstte, AI buna uyar.
        if ($memory = MemoryDraft::contextLines($phone)) {
            $lines[] = 'İŞLETME HAFIZASI (kullanıcının öğrettikleri — uy; çelişirse yeni mesaj geçerli):';
            array_push($lines, ...$memory);
            $lines[] = '';
        }
        if ($self = self::selfNames()) {
            $lines[] = 'BİZ (kullanıcı / firma — ASLA cari değil): ' . implode('; ', $self);
            $lines[] = '';
        }

        // Son konuşulan kişi / proje (29.10, firma 7: Kamuran + Yeşilyurt konuşulurken "Alçı boya yapıldı" bağlamsız okundu).
        if ($recent = self::recentSubject($phone)) {
            $lines[] = 'SON KONUŞULAN (son 30 dk): ' . $recent . ' — yeni mesajda kişi / ' . mb_strtolower(\App\Support\Labels::project())
                . ' adı geçmiyorsa ve mesaj buna uyuyorsa ("Alçı boya yapıldı", "kalanını da ödedim") bu kişi / ' . mb_strtolower(\App\Support\Labels::project())
                . ' için say; başka biri söylenirse o geçerli.';
            $lines[] = '';
        }

        if (\App\Support\Features::places()) {
            $one = \App\Support\Labels::project();
            // Mimar (Ferhat): eski başlık aynen — davranışı değişmesin (kullanıcı kararı 15.10).
            $lines[] = config('app.profile') === 'mimar'
                ? 'PROJELER (id: isim [ada/parsel]):'
                : \App\Support\Labels::projectsUpper() . ' (id: isim' . ($one === 'Proje' ? ' [ada/parsel]' : '') . ') — kaydın ait olduğu '
                    . mb_strtolower($one) . '; mesajda adı geçiyorsa ("Ergenekon kasa", "Cumhuriyet için sıva", "dünkü Ergenekon ciro") `project_id` o '
                    . mb_strtolower($one) . '. Geçmiyorsa null bırak (sistem sorar). Ödeme/tahsilatta doldurma:'
                    // Çiftlik (alışı yere yaz, 28.10 Oktay "123/50 tarla ya 15 römork gübre"): listede olmayan yer yeni açılır.
                    . (\App\Support\Features::placeCost()
                        ? ' Kullanıcı listede OLMAYAN bir yer söylüyorsa ("123/50 tarla", "123 ada 50 parsel tarlam", "yeni ahır", '
                            . '"Hangi yer?" sorusuna liste dışı bir ad) `project_id` null, `project_name` = o yerin kısa adı ("Tarla 123/50") — '
                            . 'onayla yeni yer açılır. Listedeki bir yere benziyorsa (aynı ada/parsel) onu seç, yeni açma.'
                        : '');
            $lines[] = $projects->isEmpty()
                ? '  (kayıtlı proje yok)'
                : $projects->map(fn ($p) => sprintf('  %d: %s%s', $p->id, $p->name, $p->cadastral_parcel ? " [{$p->cadastral_parcel}]" : ''))->implode("\n");
        } else {
            $lines[] = 'PROJE KULLANILMIYOR: bu işletmede proje yok — project_id ve project_name HER ZAMAN null, proje sorma.';
        }

        $lines[] = '';
        // Tip notu (28.10, kullanıcı "tipi AI'ya ipucu verelim"): kullanıcının panelde seçtiği tip — kesin bilgi değil, yön belirsizken ipucu.
        $lines[] = $parties->contains(fn ($c) => in_array($c->kind, [Party::KIND_CUSTOMER, Party::KIND_SUPPLIER], true))
            ? 'CARİLER (id: isim) — "(müşteri)" / "(mal-hizmet aldığımız)" notunu kullanıcı seçti. Mesajda fiil yoksa ya da yön belirsizse '
                . 'ipucudur: mal-hizmet aldığımız kişiye giden para büyük ihtimalle ödeme (payment), müşteriden gelen para tahsilat (collection). '
                . 'Mesajdaki açık fiil her zaman nottan önce gelir ("Ahmet\'e mal sattım" müşteri notu olmasa da satış); ipucuyla da yön '
                . 'hâlâ belirsizse sor:'
            : 'CARİLER (id: isim):';
        // Açık avansı olan cariler (Faz G): "Osman işi bitirdi" tutarsız gelince avansın karşılığı olarak tanınsın. Tek sorgu + yalnız onlar.
        $withAdvance = PartyLedgerEntry::advance()
            ->whereIn('type', [PartyLedgerEntry::TYPE_PAYMENT, PartyLedgerEntry::TYPE_COLLECTION])->distinct()->pluck('party_id')->flip();
        $lines[] = $parties->isEmpty()
            ? '  (kayıtlı cari yok)'
            : $parties->map(fn ($c) => sprintf('  %d: %s', $c->id, $c->name) . self::kindNote($c) . self::employeeNote($c)
                . (! $c->is_employee && $withAdvance->has($c->id) ? self::advanceNote($c) : ''))->implode("\n");

        // Açık anlaşma kalemleri (21.10): "beton geldi" hangi kaleme düşer — anlamdan AI seçer (agreement_delivery).
        if (\App\Support\Features::agreements() && ($open = AgreementDeliveryDraft::contextLines()) !== []) {
            $lines[] = '';
            $lines[] = 'AÇIK ANLAŞMALAR (kalem id: cari — anlaşma — kalem — kalan × fiyat; mal geldi / iş yapıldı / teslim edildi → agreement_delivery):';
            array_push($lines, ...$open);
        }

        $lines[] = '';
        $lines[] = 'GİDER KATEGORİLERİ (id: isim):';
        $lines[] = $categories->isEmpty()
            ? '  (kayıtlı kategori yok)'
            : $categories->map(fn ($k) => sprintf('  %d: %s', $k->id, $k->name))->implode("\n");

        // Stoklu satış paketi: ürün kataloğu (satış/alış/sayım bu listeden eşleşir). Eski firmalarda gösterilmez.
        if (\App\Support\Features::salesDesk() || \App\Support\Features::stockPurchases()) {
            $products = self::scoped(\App\Models\Product::active(), 'products')->with('unit')->orderBy('name')->get();
            $lines[] = '';
            $lines[] = 'ÜRÜNLER (id: ad [birim] — satış fiyatı / alış fiyatı — stok — otomatik düşme / uyarı ayarı; yazmayan ayar YOKTUR):';
            $lines[] = $products->isEmpty()
                ? '  (kayıtlı ürün yok — ürün adı geçse de product_id null)'
                : $products->map(fn ($p) => sprintf('  %d: %s%s — %s%s', $p->id, $p->name, $p->unit ? " [{$p->unit->name}]" : '',
                    ($p->default_price !== null ? 'satış ' . (float) $p->default_price : 'satış fiyatsız')
                        . (($buy = $p->buyPrice()) !== null ? ' / alış ' . $buy : ''),
                    ($p->isProduct() ? ' — stok ' . \App\Support\StockUsage::quantity($p->currentStock()) : ' — hizmet')
                        // Ayar gerçekte ne (Çiftlik 16.10: AI "zaten otomatik düşüyor" dedi, ayar yoktu) — yoksa "yok" yazar.
                        . ($p->isProduct() ? (($usage = $p->usageLabel()) !== null ? ' — ' . $usage . ' düşülüyor' : ' — otomatik düşme yok')
                            . ($p->reorder_level !== null ? ', ' . \App\Support\StockUsage::quantity((float) $p->reorder_level) . ' kalınca uyarı' : '') : '')))
                ->implode("\n");
        }

        // Ödenmemiş giderler: "kirayı ödedim" yeni gider açıp aynı kirayı ikinci kez saymasın — AI
        // ödemeyi buradaki açık borçla eşleştirir (settles_expense_id), kullanıcı teyit eder.
        if (\App\Support\Features::expenses()) {
            $open = self::scoped(Expense::query(), 'expenses')
                ->whereIn('payment_status', ['unpaid', 'partial'])
                ->latest('expense_date')->latest('id')
                ->take(25)
                ->get(['id', 'expense_date', 'amount', 'description']);

            $lines[] = '';
            $lines[] = 'ÖDENMEMİŞ GİDERLER (id: tarih — açıklama — tutar):';
            $lines[] = $open->isEmpty()
                ? '  (ödenmemiş gider yok)'
                : $open->map(fn (Expense $e) => sprintf('  %d: %s — %s — %s%s', $e->id, $e->expense_date->format('Y-m-d'),
                    $e->description ?: 'gider', (float) $e->amount, ''))->implode("\n");
        }

        return implode("\n", $lines);
    }

    /**
     * Açık avans: " (önceden verdiğimiz para 1.005.000 — iş/mal bekleniyor)" / " (peşin aldık 15.000 — mal/iş teslim edilecek)".
     * Günlük dil (kullanıcı 20.10): etiketteki "avans" kelimesi AI'ın müşteriye yazdığı cümleye geçiyordu.
     */
    /** Bu numaranın son 30 dakikadaki taslaklarından en yeni kişi ve proje: "Kamuran Narçin (cari 12) · Yeşilyurt (proje 3)". */
    private static function recentSubject(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }
        $drafts = \App\Models\WhatsappPendingExpense::where('phone', $phone)->whereIn('status', ['awaiting_confirmation', 'confirmed'])
            ->where('updated_at', '>=', now()->subMinutes(30))->latest('updated_at')->limit(5)->get(['id', 'extracted']);
        $partyId = $drafts->map(fn ($p) => (int) ($p->extracted['party_id'] ?? 0))->first(fn ($id) => $id > 0);
        $projectId = $drafts->map(fn ($p) => (int) ($p->extracted['project_id'] ?? 0))->first(fn ($id) => $id > 0);
        $party = $partyId ? Party::find($partyId, ['id', 'name']) : null;
        $project = $projectId && \App\Support\Features::places() ? Project::find($projectId, ['id', 'name']) : null;
        $parts = array_filter([
            $party ? $party->name . ' (cari ' . $party->id . ')' : null,
            $project ? $project->name . ' (' . mb_strtolower(\App\Support\Labels::project()) . ' ' . $project->id . ')' : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private static function advanceNote($party): string
    {
        $ours = AdvanceOffset::open($party, ExpenseExtractor::KIND_PURCHASE);
        if ($ours > 0) {
            return ' (önceden verdiğimiz para ' . \App\Support\Money::format($ours) . ' — iş/mal bekleniyor)';
        }
        $theirs = AdvanceOffset::open($party, ExpenseExtractor::KIND_SALE);

        return $theirs > 0 ? ' (peşin aldık ' . \App\Support\Money::format($theirs) . ' — mal/iş teslim edilecek)' : '';
    }

    /** Tip notu: yalnız tek yönlü tipler; "ikisi de" / boş tip bilgi taşımaz, personelin notu employeeNote'ta. */
    private static function kindNote($party): string
    {
        return match ($party->kind) {
            Party::KIND_CUSTOMER => ' (müşteri)',
            Party::KIND_SUPPLIER => ' (mal-hizmet aldığımız)',
            default => '',
        };
    }

    /** Personel işaretli cari: " (personel, maaş 50.000, ayın 15'i)" — maaş/avans sorularında AI bilsin. */
    private static function employeeNote($party): string
    {
        if (empty($party->is_employee)) {
            return '';
        }

        // Açık avans ve maaştan kalan KOD hesaplar ("maaşından ne kadar kaldı" sorusu bu satırdan cevaplanır).
        $advance = AdvanceOffset::open($party, ExpenseExtractor::KIND_PURCHASE);
        $salary = (float) $party->salary_amount;

        return ' (personel' . ($salary ? ', maaş ' . \App\Support\Money::format($salary) : ', maaşı bilinmiyor')
            . ($party->salary_day ? ', ayın ' . $party->salary_day . "'i" : '')
            . ($advance > 0 ? ', açık avans ' . \App\Support\Money::format($advance) : '')
            . ($salary && $advance > 0 ? ', maaştan kalan ' . \App\Support\Money::format(max(0, $salary - $advance)) : '') . ')';
    }
}
