<?php

namespace App\Filament\Pages;

use App\Models\CompanySettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class CompanySettingsPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'Ayarlar';

    protected static ?string $navigationLabel = 'Ayarlar';

    protected string $view = 'filament.pages.company-settings';

    public array $data = [];

    public function mount(): void
    {
        $this->form->fill(CompanySettings::current()->only([
            'title', 'address', 'phone', 'email', 'tax_office', 'tax_number',
            'quote_template', 'contract_template',
        ]));
    }

    public function form(Schema $schema): Schema
    {
        $placeholderHint = 'Kullanılabilir yer tutucular: '
            . '<<firma_ad>>, <<firma_adres>>, <<firma_telefon>>, <<firma_vergi>>, '
            . '<<cari_ad>>, <<baslik>>, <<tarih>>, <<gecerlilik>>, <<proje_ad>>, '
            . '<<kalem_tablosu>>, <<toplam>>, <<odeme_plani>>, <<imza_alani>>';

        return $schema
            ->components([
                Section::make('Firma Bilgileri')
                    ->description('Çıktıların (teklif/sözleşme) üst başlığında görünür. "Biz kimiz."')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('Ünvan')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('address')->label('Adres')->maxLength(255)->columnSpanFull(),
                        TextInput::make('phone')->label('Telefon')->maxLength(255),
                        TextInput::make('email')->label('E-posta')->maxLength(255),
                        TextInput::make('tax_office')->label('Vergi Dairesi')->maxLength(255),
                        TextInput::make('tax_number')->label('Vergi No')->maxLength(255),
                    ]),

                Section::make('Teklif Şablonu')
                    ->description($placeholderHint)
                    ->schema([
                        Textarea::make('quote_template')
                            ->label('Teklif çıktı metni')
                            ->rows(8)
                            ->columnSpanFull(),
                    ]),

                Section::make('Sözleşme Şablonu')
                    ->description($placeholderHint)
                    ->schema([
                        Textarea::make('contract_template')
                            ->label('Sözleşme çıktı metni')
                            ->rows(8)
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Kaydet')
                ->icon(Heroicon::OutlinedCheck)
                ->action('save'),
        ];
    }

    public function save(): void
    {
        CompanySettings::current()->update($this->form->getState());

        Notification::make()
            ->success()
            ->title('Ayarlar kaydedildi')
            ->send();
    }
}
