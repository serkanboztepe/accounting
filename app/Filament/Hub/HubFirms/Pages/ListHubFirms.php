<?php

namespace App\Filament\Hub\HubFirms\Pages;

use App\Filament\Hub\HubFirms\HubFirmResource;
use App\Models\HubFirm;
use App\Tenancy\FirmProvisioner;
use App\Tenancy\Tenancy;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Exceptions\Halt;
use Throwable;

class ListHubFirms extends ListRecords
{
    protected static string $resource = HubFirmResource::class;

    protected function getHeaderActions(): array
    {
        // create sayfası yok → modal açılır; kaydedince firmanın sayfasına (numara eklemeye) git.
        return [
            CreateAction::make()
                ->schema(HubFirmResource::components(creating: true))
                ->using(fn (array $data) => $this->createFirm($data))
                ->successRedirectUrl(fn ($record) => HubFirmResource::getUrl('edit', ['record' => $record])),
        ];
    }

    /** Tek panel: yeni veritabanı aç + kur + ilk kullanıcı. Eski hub'da (TENANCY kapalı): adres. */
    private function createFirm(array $data): HubFirm
    {
        $firm = new HubFirm([
            'name'      => $data['name'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        if (! Tenancy::enabled()) {
            $firm->url = $data['url'];
            $firm->save();

            return $firm;
        }

        $firm->settings = array_merge(HubFirm::DEFAULT_SETTINGS, ['profile' => $data['profile'] ?? null]);
        $firm->database = FirmProvisioner::databaseNameFor($data['code']);

        if (HubFirm::where('database', $firm->database)->exists()) {
            $this->fail("\"{$data['code']}\" kısa adı başka bir firmada kullanılıyor, başka bir kısa ad seç.");
        }

        $firm->save();

        try {
            FirmProvisioner::create($firm, $data['user_name'], $data['user_email'], $data['user_password']);
        } catch (Throwable $e) {
            $firm->delete();
            report($e);
            $this->fail($e->getMessage());
        }

        return $firm;
    }

    private function fail(string $message): never
    {
        Notification::make()->danger()->title('Firma oluşturulamadı')->body($message)->persistent()->send();

        throw new Halt();
    }
}
