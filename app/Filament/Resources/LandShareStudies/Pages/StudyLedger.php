<?php

namespace App\Filament\Resources\LandShareStudies\Pages;

use App\Filament\Resources\LandShareStudies\LandShareStudyResource;
use App\Services\LandShare\ShareCalculator;
use App\Services\LandShare\ShareResult;
use App\Services\LandShare\StudyData;
use App\Services\LandShare\StudyValidator;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

class StudyLedger extends Page
{
    use InteractsWithRecord;

    protected static string $resource = LandShareStudyResource::class;

    protected string $view = 'filament.land-share.ledger';

    /** Hangi kişilerin "Hesabı Açıkla" kutusu açık. */
    public array $expanded = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return 'Hisse Cetveli — ' . $this->record->name;
    }

    /** Breadcrumb'da kayıt adını tekrarlama — ortadaki kayıt breadcrumb'ı zaten gösteriyor. */
    public function getBreadcrumb(): string
    {
        return 'Hisse Cetveli';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Çıktı Al / Yazdır')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('primary')
                ->url(fn () => route('land-share.print', ['study' => $this->record]))
                ->extraAttributes(fn () => \App\Support\Printing::iframeAttributes(route('land-share.print', ['study' => $this->record]))),

            Action::make('edit')
                ->label('Veri Girişine Dön')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->url(fn () => LandShareStudyResource::getUrl('build', ['record' => $this->record])),
        ];
    }

    public function toggleExpand(string $key): void
    {
        if (($i = array_search($key, $this->expanded, true)) !== false) {
            unset($this->expanded[$i]);
        } else {
            $this->expanded[] = $key;
        }
    }

    private function data(): StudyData
    {
        return $this->record->toStudyData();
    }

    /** Kontrol satırları — accordion rozetleriyle aynı mantık. */
    public function getChecks(): array
    {
        $v = new StudyValidator();
        $d = $this->data();

        $checks = [
            ['label' => 'Mevcut hisseler toplamı 1/1', 'ok' => $v->currentSharesComplete($d)],
            ['label' => 'Tüm bağımsız bölümler dağıtıldı', 'ok' => $v->allSectionsAllocated($d)],
            ['label' => 'Her BB\'nin dağılımı 1/1', 'ok' => $v->everySectionComplete($d)],
        ];

        // Arsa payı girilmişse ilgili kontrolü de göster.
        $hasAnyArsa = collect($d->sections)->contains(fn ($s) => $s['arsa_pay'] !== null);
        if ($hasAnyArsa) {
            $checks[] = ['label' => 'Arsa payları toplamı 1/1 (Arsa Paylı için)', 'ok' => $v->arsaSharesComplete($d)];
        }

        return $checks;
    }

    /** Yöntem her zaman veriye göre otomatik seçilir (manuel seçim yok). */
    public function effectiveMethod(): ?string
    {
        return (new StudyValidator())->defaultMethod($this->data());
    }

    public function getResult(): ?ShareResult
    {
        $method = $this->effectiveMethod();

        if ($method === null) {
            return null;
        }

        return (new ShareCalculator())->calculate($this->data(), $method);
    }
}
