<?php

namespace Tests\Feature\Agent;

use App\Models\AssistantMemory;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Services\Agent\Tools\FindParty;
use App\Services\Agent\Tools\PartyOverview;
use App\Services\Agent\Tools\ReadTools;
use App\Services\Agent\Tools\Report;
use App\Support\ModuleProfiles;
use App\Support\PartyStatement;
use App\Support\ProjectCost;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Ajanın okuma araçları (plan v5 Aşama B): rakamlar panelle aynı kaynaktan, kişi eşleşmesi sessiz değil (aday + neden),
 * hiçbir araç veri değiştirmez, araç hatası turu koparmaz. Örnek veri: Kaya 08.11 (Gürbüz Toprak — 742.000 eski borç, 120.000 ödeme).
 */
class ReadToolsTest extends TestCase
{
    use DatabaseTransactions;

    private Party $gurbuz;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('muteahhit', ['smart_assistant' => true, 'agreements' => true]), 'app.profile' => 'muteahhit']);
        $this->travelTo(now('Europe/Istanbul')->setDate(2026, 11, 9)->setTime(12, 0)->setTimezone(config('app.timezone')));

        $this->gurbuz = Party::create(['name' => 'Gürbüz Toprak']);
        PartyLedgerEntry::create(['party_id' => $this->gurbuz->id, 'type' => 'alis', 'amount' => 742000, 'entry_date' => '2026-11-01',
            'description' => 'Borç kaydı — eski borç']);
        PartyLedgerEntry::create(['party_id' => $this->gurbuz->id, 'type' => 'odeme', 'amount' => 120000, 'entry_date' => '2026-11-08',
            'description' => 'Ödeme']);
    }

    private function names(array $r): array
    {
        return array_column($r['adaylar'] ?? [], 'ad');
    }

    public function test_find_party_handles_turkish_letters_suffixes_titles_and_short_names(): void
    {
        foreach (['Gürbüz Toprak', 'gurbuz toprak', 'GÜRBÜZ TOPRAK', "Gürbüz Toprak'a", 'gürbüz', 'Gurbuz', 'Gürbüz usta', 'Toprak', 'Gürbüs Toprak', 'gurbuz toprag'] as $said) {
            $r = FindParty::run(['name' => $said]);
            $this->assertSame('Gürbüz Toprak', $this->names($r)[0] ?? null, $said);
            $this->assertSame('güçlü', $r['adaylar'][0]['güç'], $said);
            $this->assertSame($this->gurbuz->id, $r['adaylar'][0]['id']);
        }
        $r = FindParty::run(['name' => 'Gürbüz Toprak']);
        $this->assertStringContainsString('borcun 622.000,00', $r['adaylar'][0]['bakiye']);
        $this->assertStringContainsString('2026-11-08', $r['adaylar'][0]['son_hareket']);
        $this->assertArrayNotHasKey('not', $r, 'tek güçlü aday → uyarı yok');
    }

    /** İşletmenin kendi adı kişi gibi eşleşmez: "bu sensin" denir. */
    public function test_find_party_recognises_the_business_itself(): void
    {
        \App\Models\CompanySettings::query()->delete();
        \App\Models\CompanySettings::create(['title' => 'Deneme Yapı Market']);
        $r = FindParty::run(['name' => 'Deneme Yapı Market', '_phone' => '']);
        $this->assertSame('Deneme Yapı Market', $r['senin_firman']);
        $this->assertArrayNotHasKey('adaylar', $r);
        $this->assertArrayNotHasKey('senin_firman', FindParty::run(['name' => 'Gürbüz Toprak', '_phone' => '']));
    }

    public function test_find_party_never_matches_silently_when_unsure(): void
    {
        Party::create(['name' => 'Gürbüz Kaya']);

        // İki güçlü aday → sor.
        $r = FindParty::run(['name' => 'Gürbüz']);
        $this->assertEqualsCanonicalizing(['Gürbüz Toprak', 'Gürbüz Kaya'], $this->names($r));
        $this->assertStringContainsString('sor', $r['not']);

        // Tam ad yazılınca yalnız o güçlü; öteki zayıf/orta kalır.
        $r = FindParty::run(['name' => 'Gürbüz Kaya']);
        $this->assertSame('Gürbüz Kaya', $this->names($r)[0]);
        $this->assertSame('güçlü', $r['adaylar'][0]['güç']);
        $this->assertNotSame('güçlü', collect($r['adaylar'])->firstWhere('ad', 'Gürbüz Toprak')['güç']);

        // Yazılanın bir kısmı tutmuyor → güçlü değil, uyarı var ("Sinan usta" ↔ "Sinan Sele" dersi).
        $r = FindParty::run(['name' => 'Gürbüz Demir']);
        $this->assertNotContains('güçlü', array_column($r['adaylar'], 'güç'));
        $this->assertStringContainsString('sessizce eşleştirme', $r['not']);

        // Hiç yok → yeni kişi olabilir. "Tokgöz" ≠ "stok" (06.11 Akkuş).
        Party::create(['name' => 'Stok Deposu']);
        $r = FindParty::run(['name' => 'Tokgöz']);
        $this->assertSame(0, $r['bulunan']);
        $this->assertStringContainsString('Yeni kişi', $r['not']);
    }

    public function test_find_party_uses_memory_aliases(): void
    {
        $firm = Party::create(['name' => 'Yıldız Asansör Sanayi']);
        AssistantMemory::create(['kind' => 'alias', 'subject' => 'Asansörcü', 'fact' => 'Asansörcü = Yıldız Asansör Sanayi', 'party_id' => $firm->id,
            'status' => 'active', 'source' => 'test']);

        $r = FindParty::run(['name' => 'asansörcüye']);
        $this->assertSame($firm->id, $r['adaylar'][0]['id']);
        $this->assertStringContainsString('hafızada', $r['adaylar'][0]['neden']);
    }

    public function test_party_overview_matches_the_statement_and_gives_refs(): void
    {
        $r = PartyOverview::run(['party_id' => $this->gurbuz->id]);

        $this->assertEqualsWithDelta(622000, $r['bakiye']['tutar'], 0.01);
        $this->assertSame('biz ona borçluyuz', $r['bakiye']['durum']);
        $this->assertEqualsWithDelta(abs(PartyStatement::build($this->gurbuz)['balance']), $r['bakiye']['tutar'], 0.01, 'panel ekstresiyle aynı');
        $this->assertSame(2, $r['hareket_sayısı']);
        $last = end($r['son_hareketler']);
        $this->assertSame('ledger:' . PartyLedgerEntry::where('party_id', $this->gurbuz->id)->where('type', 'odeme')->value('id'), $last['ref']);
        $this->assertEqualsWithDelta(120000, $last['borç (o bize)'], 0.01);
        $this->assertEqualsWithDelta(-622000, $last['bakiye_sonra'], 0.01);
        $this->assertArrayNotHasKey('maaş', $r);

        $this->assertArrayHasKey('hata', PartyOverview::run(['party_id' => 999999999]));
    }

    public function test_party_overview_shows_salary_state_from_the_salary_ledger(): void
    {
        $emp = Party::create(['name' => 'Araç Testi Personel', 'is_employee' => true, 'salary_amount' => 50000, 'salary_day' => 8, 'salary_auto' => true]);
        PartyLedgerEntry::create(['party_id' => $emp->id, 'type' => 'alis', 'amount' => 50000, 'entry_date' => '2026-11-08',
            'description' => 'Maaş — Kasım 2026', 'salary_period' => '2026-11']);
        PartyLedgerEntry::create(['party_id' => $emp->id, 'type' => 'odeme', 'amount' => 20000, 'entry_date' => '2026-11-08', 'description' => 'Maaş ödemesi']);

        $r = PartyOverview::run(['party_id' => $emp->id]);
        $state = \App\Support\SalaryLedger::state($emp);
        $this->assertEqualsWithDelta($state['owed'], $r['maaş']['ödenecek_maaş_borcu'], 0.01);
        $this->assertEqualsWithDelta(30000, $r['maaş']['ödenecek_maaş_borcu'], 0.01);
        $this->assertStringContainsString('kalan 30000', $r['maaş']['açık_aylar'][0]);
    }

    public function test_report_numbers_come_from_the_same_classes_as_the_panel(): void
    {
        $project = Project::create(['name' => 'Araç Testi Şantiye', 'status' => 'active']);
        $category = ExpenseCategory::firstOrCreate(['name' => 'Araç Testi Kategori']);
        Expense::create(['project_id' => $project->id, 'expense_category_id' => $category->id, 'expense_date' => '2026-11-05', 'amount' => 7000,
            'payment_status' => 'paid', 'description' => 'Hafriyat']);
        PartyLedgerEntry::create(['party_id' => $this->gurbuz->id, 'type' => 'alis', 'amount' => 30000, 'entry_date' => '2026-11-06',
            'project_id' => $project->id, 'description' => 'Demir']);

        $r = Report::run(['kind' => 'project_cost', 'project_id' => $project->id]);
        $this->assertEqualsWithDelta(ProjectCost::total($project->id), $r['toplam_maliyet'], 0.01);
        $this->assertEqualsWithDelta(37000, $r['toplam_maliyet'], 0.01);

        $all = Report::run(['kind' => 'project_cost']);
        $this->assertEqualsWithDelta(37000, collect($all['projeler'])->firstWhere('id', $project->id)['toplam_maliyet'], 0.01);

        $pay = Report::run(['kind' => 'payables']);
        $row = collect($pay['liste'])->firstWhere('id', $this->gurbuz->id);
        $this->assertEqualsWithDelta(652000, $row['tutar'], 0.01, '622.000 + 30.000 alış');
        $this->assertEqualsWithDelta(\App\Support\PartyBalances::payables()->sum('balance'), $pay['toplam'], 0.01);
        $this->assertNull(collect(Report::run(['kind' => 'receivables'])['liste'])->firstWhere('id', $this->gurbuz->id));

        $period = Report::run(['kind' => 'period', 'date_from' => '2026-11-01', 'date_to' => '2026-11-30']);
        $expected = \App\Support\MonthlyResult::forRange(now()->setDate(2026, 11, 1), now()->setDate(2026, 11, 30));
        $this->assertEqualsWithDelta($expected['result'], $period['sonuç (yaklaşık)'], 0.01);
        $this->assertEqualsWithDelta($expected['purchases'], $period['alışlar'], 0.01);

        $this->assertArrayHasKey('hata', Report::run(['kind' => 'period']));
        $this->assertArrayHasKey('hata', Report::run(['kind' => 'place']));
        $this->assertArrayHasKey('hata', Report::run(['kind' => 'yok']));
        $this->assertArrayHasKey('hata', Report::run(['kind' => 'project_cost', 'project_id' => 999999999]));
    }

    public function test_registry_lists_tools_runs_them_and_survives_errors(): void
    {
        $names = array_column(ReadTools::definitions(), 'name');
        $this->assertSame(['find_party', 'party_overview', 'records', 'report', 'search_conversations', 'sql_select'], $names);
        foreach (ReadTools::definitions() as $tool) {
            $this->assertSame('object', $tool['input_schema']['type'], $tool['name']);
            $this->assertNotEmpty($tool['description']);
            $this->assertStringNotContainsString('query_records', $tool['description'], $tool['name']);
        }

        $r = ReadTools::run('records', ['party_id' => $this->gurbuz->id, 'types' => ['odeme']]);
        $this->assertSame(1, $r['bulunan']);
        $this->assertStringStartsWith('ledger:', $r['satırlar'][0]['ref']);

        $r = ReadTools::run('sql_select', ['sql' => "SELECT name FROM parties WHERE id = {$this->gurbuz->id}"]);
        $this->assertSame([['name' => 'Gürbüz Toprak']], $r['satırlar']);
        $this->assertArrayHasKey('hata', ReadTools::run('sql_select', ['sql' => 'DELETE FROM parties']));
        $this->assertArrayHasKey('hata', ReadTools::run('sql_select', ['sql' => 'SELECT email FROM users']));

        $this->assertArrayHasKey('hata', ReadTools::run('olmayan_arac', []));
        $this->assertArrayHasKey('hata', ReadTools::run('party_overview', []));
        $this->assertFalse(ReadTools::has('propose'));
    }

    /** Okuma araçları hiçbir şey yazmaz: tümü çalıştıktan sonra tablolar aynı. */
    public function test_read_tools_write_nothing(): void
    {
        $count = fn () => [Party::count(), PartyLedgerEntry::count(), Expense::count(), AssistantMemory::count(),
            \App\Models\WhatsappPendingExpense::count(), PartyLedgerEntry::max('updated_at'), Party::max('updated_at')];
        $before = $count();

        ReadTools::run('find_party', ['name' => 'Gürbüz']);
        ReadTools::run('party_overview', ['party_id' => $this->gurbuz->id]);
        ReadTools::run('records', []);
        foreach (Report::KINDS as $kind) {
            ReadTools::run('report', ['kind' => $kind, 'date_from' => '2026-11-01', 'date_to' => '2026-11-09']);
        }
        ReadTools::run('search_conversations', ['text' => 'gürbüz']);
        ReadTools::run('sql_select', ['sql' => 'SELECT count(*) FROM parties']);

        $this->assertSame($before, $count());
    }
}
