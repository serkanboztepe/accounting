<?php

namespace Tests\Feature\LandShare;

use App\Filament\Resources\LandShareStudies\Pages\StudyBuilder;
use App\Models\LandSection;
use App\Models\LandShareStudy;
use App\Models\Project;
use App\Models\User;
use App\Services\LandShare\ShareCalculator;
use App\Services\LandShare\StudyValidator;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Arsa payı girişi (yıldız talebi): BB başına arsa payı → hisse arsa payına
 * göre; eksikse eşit hesaba açık uyarıyla döner.
 */
class LandShareInputTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /**
     * Ali 1/2 + Veli 1/2 arsa sahibi. 3 BB: BB1 dükkan (arsa 500/1000) → Ali,
     * BB2 (300/1000) → Veli, BB3 (200/1000) → Müteahhit.
     *
     * @param  array<int, ?int>  $arsaPays  BB1..BB3 arsa payları
     * @return array{0: LandShareStudy, 1: \Illuminate\Support\Collection}
     */
    private function makeStudy(array $arsaPays = [500, 300, 200]): array
    {
        $project = Project::create(['name' => 'Arsa P', 'status' => 'active']);
        $study = LandShareStudy::create([
            'project_id' => $project->id, 'name' => 'Arsa', 'status' => 'draft',
            'block_count' => 1, 'units_per_block' => 3, 'land_share_denominator' => 1000,
        ]);
        $ali = $study->shareholders()->create(['name' => 'Ali', 'current_pay' => 1, 'current_payda' => 2]);
        $veli = $study->shareholders()->create(['name' => 'Veli', 'current_pay' => 1, 'current_payda' => 2]);
        $mut = $study->shareholders()->create(['name' => 'Müteahhit', 'current_pay' => 0, 'current_payda' => 1, 'is_contractor' => true]);
        $study->syncStructure();

        $sections = LandSection::whereIn('block_id', $study->blocks()->pluck('id'))->orderBy('sort')->get();
        foreach ([$ali, $veli, $mut] as $i => $holder) {
            $sections[$i]->allocations()->create(['shareholder_id' => $holder->id, 'pay' => 1, 'payda' => 1]);
        }

        return [$study, $sections, $arsaPays];
    }

    private function item(StudyBuilder $inst, int $i): array
    {
        return array_values($inst->data['sections'])[$i];
    }

    private function remainingByName(LandShareStudy $study, string $method): array
    {
        $result = (new ShareCalculator())->calculate($study->fresh()->toStudyData(), $method);

        return collect($result->rows)->mapWithKeys(fn ($r) => [$r->name => (string) $r->remaining])->all();
    }

    public function test_step2_saves_land_shares_and_reloads_with_common_denominator(): void
    {
        [$study, $sections, $pays] = $this->makeStudy();

        $inst = Livewire::test(StudyBuilder::class, ['record' => $study->id])->instance();

        // Payda, çalışmanın ortak paydasından önerilir.
        $this->assertEquals(1000, $this->item($inst, 0)['arsa_payda']);
        $this->assertNull($this->item($inst, 0)['arsa_pay']);

        $keys = array_keys($inst->data['sections']);
        foreach ($pays as $i => $pay) {
            $inst->data['sections'][$keys[$i]]['arsa_pay'] = $pay;
        }
        $inst->data['sections'][$keys[2]]['arsa_payda'] = null; // boş payda → ortak payda
        $inst->saveStep2();

        $this->assertSame([500, 300, 200], $sections->map(fn ($s) => $s->fresh()->arsa_pay)->all());
        $this->assertSame([1000, 1000, 1000], $sections->map(fn ($s) => $s->fresh()->arsa_payda)->all());

        // Atamalar korunmuş olmalı (saveStep2 sil-yeniden yazar).
        $this->assertSame(3, $sections->filter(fn ($s) => $s->fresh()->allocations()->count() === 1)->count());

        // Boş pay → arsa payı silinir.
        $inst->data['sections'][$keys[0]]['arsa_pay'] = '';
        $inst->saveStep2();
        $this->assertNull($sections[0]->fresh()->arsa_pay);
        $this->assertNull($sections[0]->fresh()->arsa_payda);
    }

    public function test_complete_land_shares_weight_the_ledger(): void
    {
        [$study, $sections, $pays] = $this->makeStudy();
        foreach ($pays as $i => $pay) {
            $sections[$i]->update(['arsa_pay' => $pay, 'arsa_payda' => 1000]);
        }

        $data = $study->fresh()->toStudyData();
        $this->assertSame('complete', (new StudyValidator())->arsaSharesState($data)['state']);
        $this->assertSame(ShareCalculator::ARSA_PAYLI, (new StudyValidator())->defaultMethod($data));

        // Arsa paylı: dükkanı alan Ali 1/2 tutar; eşit hesapta herkes 1/3 olurdu.
        $this->assertSame(['Ali' => '1/2', 'Veli' => '3/10', 'Müteahhit' => '1/5'],
            $this->remainingByName($study, ShareCalculator::ARSA_PAYLI));
        $this->assertSame(['Ali' => '1/3', 'Veli' => '1/3', 'Müteahhit' => '1/3'],
            $this->remainingByName($study, ShareCalculator::BLOK_DAIRE));

        $this->get("/admin/land-share-studies/{$study->id}/cetvel")
            ->assertOk()
            ->assertSee('Arsa payına göre hesaplandı');
    }

    public function test_partial_land_shares_fill_remaining_into_empty_sections(): void
    {
        // Kullanıcı kararı 11.11: boş BB'lere 1/1'e kalan pay eşit bölünür (DB'ye yazılmaz).
        [$study, $sections] = $this->makeStudy();
        $sections[0]->update(['arsa_pay' => 500, 'arsa_payda' => 1000]);
        $sections[1]->update(['arsa_pay' => 300, 'arsa_payda' => 1000]);
        // BB3 boş → kalan 200/1000 ona sayılır

        $data = $study->fresh()->toStudyData();
        $state = (new StudyValidator())->arsaSharesState($data);
        $this->assertSame('complete', $state['state']);
        $this->assertSame(1, $state['auto']);
        $this->assertSame(ShareCalculator::ARSA_PAYLI, (new StudyValidator())->defaultMethod($data));
        $this->assertSame(['Ali' => '1/2', 'Veli' => '3/10', 'Müteahhit' => '1/5'],
            $this->remainingByName($study, ShareCalculator::ARSA_PAYLI));

        // Veritabanı aynen kalır.
        $this->assertNull($sections[2]->fresh()->arsa_pay);

        $this->get("/admin/land-share-studies/{$study->id}/cetvel")
            ->assertOk()
            ->assertSee('Arsa payına göre hesaplandı')
            ->assertSee('girilmeyen 1 BB');

        $this->get(route('land-share.print', ['study' => $study->id]))
            ->assertOk()
            ->assertSee('BAĞIMSIZ BÖLÜM ARSA PAYLARI')
            ->assertSee('200/1000')
            ->assertSee('Arsa payı girilmeyen 1 bağımsız bölüme kalan pay eşit bölünmüştür');
    }

    public function test_no_land_shares_counts_every_section_equal_and_prints_table(): void
    {
        // 333/1 vakası: hiç arsa payı yok → her BB 1/N, tablo çıkar, sonuç eşit hesapla aynı.
        [$study] = $this->makeStudy();
        $study->update(['land_share_denominator' => null]);

        $data = $study->fresh()->toStudyData();
        $state = (new StudyValidator())->arsaSharesState($data);
        $this->assertSame('complete', $state['state']);
        $this->assertSame(3, $state['auto']);
        $this->assertSame(['Ali' => '1/3', 'Veli' => '1/3', 'Müteahhit' => '1/3'],
            $this->remainingByName($study, ShareCalculator::ARSA_PAYLI));
        $this->assertSame(['Ali' => '1/3', 'Veli' => '1/3', 'Müteahhit' => '1/3'],
            $this->remainingByName($study, ShareCalculator::BLOK_DAIRE));

        $this->get(route('land-share.print', ['study' => $study->id]))
            ->assertOk()
            ->assertSee('BAĞIMSIZ BÖLÜM ARSA PAYLARI')
            ->assertSee('1/3')
            ->assertDontSee('"num">/</td>', false);
    }

    public function test_equal_split_uses_users_denominator_when_divisible(): void
    {
        $sections = array_fill_keys(range(1, 9), ['arsa_pay' => null, 'arsa_payda' => null]);
        [$filled, $keys, $each] = StudyValidator::fillMissingArsaShares($sections, 9);
        $this->assertCount(9, $keys);
        $this->assertSame('1/9', $each);
        $this->assertSame([1, 9, true], [$filled[1]['arsa_pay'], $filled[1]['arsa_payda'], $filled[1]['auto']]);

        [, , $each] = StudyValidator::fillMissingArsaShares(array_slice($sections, 0, 3, true), 1000);
        $this->assertSame('1/3', $each);   // 1000 / 3 bölünmez → sade yaz
    }

    public function test_overflowing_land_shares_fall_back_to_equal_with_warning(): void
    {
        [$study, $sections] = $this->makeStudy();
        $sections[0]->update(['arsa_pay' => 600, 'arsa_payda' => 1000]);
        $sections[1]->update(['arsa_pay' => 500, 'arsa_payda' => 1000]);
        // BB3 boş ama toplam zaten 1100/1000 → dağıtılacak pay yok

        $data = $study->fresh()->toStudyData();
        $state = (new StudyValidator())->arsaSharesState($data);
        $this->assertSame('incomplete', $state['state']);
        $this->assertSame(1, $state['missing']);
        $this->assertSame('1100/1000', $state['sum']->toStringOver(1000));
        $this->assertSame(ShareCalculator::BLOK_DAIRE, (new StudyValidator())->defaultMethod($data));

        $this->get("/admin/land-share-studies/{$study->id}/cetvel")
            ->assertOk()
            ->assertSee('Arsa payları tutmuyor')
            ->assertSee('1100/1000');

        $this->get(route('land-share.print', ['study' => $study->id]))
            ->assertOk()
            ->assertSee('her bağımsız bölüm eşit sayılmıştır')
            ->assertDontSee('BAĞIMSIZ BÖLÜM ARSA PAYLARI');
    }

    public function test_bulk_entry_modal_saves_all_land_shares(): void
    {
        [$study, $sections] = $this->makeStudy();
        $inst = Livewire::test(StudyBuilder::class, ['record' => $study->id])->instance();

        $inst->applyLandShares([
            'denominator'               => 2400,
            'bb_' . $sections[0]->id    => 1200,
            'bb_' . $sections[1]->id    => 720,
            'bb_' . $sections[2]->id    => 480,
        ]);

        $this->assertSame(2400, $study->fresh()->land_share_denominator);
        $this->assertSame([1200, 720, 480], $sections->map(fn ($s) => $s->fresh()->arsa_pay)->all());
        $this->assertSame([2400, 2400, 2400], $sections->map(fn ($s) => $s->fresh()->arsa_payda)->all());
        $this->assertEquals(1200, $this->item($inst, 0)['arsa_pay']);

        // Atamalara dokunulmamış olmalı.
        $this->assertSame(1, $sections[0]->fresh()->allocations()->count());
        $this->assertSame(ShareCalculator::ARSA_PAYLI, (new StudyValidator())->defaultMethod($study->fresh()->toStudyData()));
    }

    public function test_print_lists_land_shares_when_complete(): void
    {
        [$study, $sections, $pays] = $this->makeStudy();
        foreach ($pays as $i => $pay) {
            $sections[$i]->update(['arsa_pay' => $pay, 'arsa_payda' => 1000]);
        }

        $this->get(route('land-share.print', ['study' => $study->id]))
            ->assertOk()
            ->assertSee('BAĞIMSIZ BÖLÜM ARSA PAYLARI')
            ->assertSee('500/1000')
            ->assertSee('1000/1000')
            ->assertSee('arsa paylarına göre hesaplanmıştır');
    }

    public function test_builder_renders_land_share_fields(): void
    {
        [$study] = $this->makeStudy();

        $this->get("/admin/land-share-studies/{$study->id}/olustur")
            ->assertOk()
            ->assertSee('Arsa Payı Paydası')
            ->assertSee('A Blok — BB Sayısı')
            ->assertSee('Arsa payı kontrolü');
    }

    public function test_yildiz_case_remaining_share_fills_empty_sections_with_one_eighteenth(): void
    {
        // Canlıdaki #8: 16 BB, sadece A-13 ve A-14 = 2/18, ortak payda girilmemiş.
        $project = Project::create(['name' => 'Hışır P', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $project->id, 'name' => 'Hışır', 'status' => 'draft',
            'block_count' => 1, 'units_per_block' => 16]);
        $holder = $study->shareholders()->create(['name' => 'Taner', 'current_pay' => 1, 'current_payda' => 1, 'is_contractor' => true]);
        $study->syncStructure();
        $sections = LandSection::whereIn('block_id', $study->blocks()->pluck('id'))->orderBy('sort')->get();
        foreach ($sections as $s) {
            $s->allocations()->create(['shareholder_id' => $holder->id, 'pay' => 1, 'payda' => 1]);
        }
        $sections[12]->update(['arsa_pay' => 2, 'arsa_payda' => 18]);
        $sections[13]->update(['arsa_pay' => 2, 'arsa_payda' => 18]);

        // Hesapta kendiliğinden tamamlanır (her boşa 1/18), DB'ye yazmadan.
        $state = (new StudyValidator())->arsaSharesState($study->fresh()->toStudyData());
        $this->assertSame('complete', $state['state']);
        $this->assertSame(14, $state['auto']);
        $this->assertSame('18/18', $state['sum']->toStringOver($state['denominator']));
        $this->assertNull($sections[0]->fresh()->arsa_pay);
        $this->get("/admin/land-share-studies/{$study->id}/cetvel")->assertSee('girilmeyen 14 BB');
        $this->get(route('land-share.print', ['study' => $study->id]))->assertSee('1/18');

        $inst = Livewire::test(StudyBuilder::class, ['record' => $study->id])->instance();
        [, $count, $each] = $inst->applyRemainingLandShare($inst->data['sections']);
        $this->assertSame(14, $count);
        $this->assertSame('1/18', $each);

        $inst->fillRemainingLandShare();

        $this->assertSame([2, 18], [$sections[12]->fresh()->arsa_pay, $sections[12]->fresh()->arsa_payda]);
        $this->assertSame([1, 18], [$sections[0]->fresh()->arsa_pay, $sections[0]->fresh()->arsa_payda]);
        $this->assertSame([1, 18], [$sections[15]->fresh()->arsa_pay, $sections[15]->fresh()->arsa_payda]);

        $data = $study->fresh()->toStudyData();
        $this->assertSame('complete', (new StudyValidator())->arsaSharesState($data)['state']);
        $this->assertSame(ShareCalculator::ARSA_PAYLI, (new StudyValidator())->defaultMethod($data));
    }

    public function test_remaining_share_does_nothing_when_already_full(): void
    {
        [$study, $sections] = $this->makeStudy();
        $sections[0]->update(['arsa_pay' => 1000, 'arsa_payda' => 1000]);

        $inst = Livewire::test(StudyBuilder::class, ['record' => $study->id])->instance();
        [, $count] = $inst->applyRemainingLandShare($inst->data['sections']);
        $this->assertSame(0, $count);
    }
}
