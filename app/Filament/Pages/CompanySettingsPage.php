<?php

namespace App\Filament\Pages;

use App\Models\CompanySettings;
use App\Support\CariLock;
use Closure;
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
                    ->visible(fn (): bool => (bool) config('modules.quotes'))
                    ->schema([
                        Textarea::make('quote_template')
                            ->label('Teklif çıktı metni')
                            ->rows(8)
                            ->columnSpanFull(),
                    ]),

                Section::make('Sözleşme Şablonu')
                    ->description($placeholderHint)
                    ->visible(fn (): bool => (bool) (config('modules.contracts')
                        || config('modules.sales_contracts')
                        || config('modules.purchase_contracts')))
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
            $this->cariPinAction(),
            Action::make('save')
                ->label('Kaydet')
                ->icon(Heroicon::OutlinedCheck)
                ->action('save'),
        ];
    }

    /** Cari kilidi PIN'i — sadece CARI_LOCK açık kurulumlarda. Değiştirmek için mevcut PIN şart. */
    private function cariPinAction(): Action
    {
        // type=text + gizleme: tarayıcı kayıtlı şifre önermesin/kaydetmesin.
        $pinInput = fn (string $name, string $label) => TextInput::make($name)
            ->label($label)
            ->required()
            ->extraInputAttributes([
                'inputmode' => 'numeric',
                'autocomplete' => 'off',
                'data-1p-ignore' => '',
                'data-lpignore' => 'true',
                'style' => '-webkit-text-security: disc; letter-spacing: .3em;',
            ]);

        return Action::make('cariPin')
            ->label('Cari PIN')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('gray')
            ->visible(fn (): bool => CariLock::enabled())
            ->modalHeading(fn (): string => CariLock::hasPin() ? 'Cari PIN\'ini değiştir' : 'Cari PIN belirle')
            ->modalDescription('Cariler kilitliyken bu PIN sorulur. 4–6 haneli bir sayı olsun.')
            ->modalSubmitActionLabel('Kaydet')
            ->schema(fn (): array => array_values(array_filter([
                CariLock::hasPin()
                    ? $pinInput('current_pin', 'Mevcut PIN')
                        ->rule(fn () => function (string $attribute, $value, Closure $fail) {
                            if (! CariLock::attemptPin((string) $value)) {
                                $fail(CariLock::lastAttemptMessage());
                            }
                        })
                    : null,
                $pinInput('pin', 'Yeni PIN')
                    ->regex('/^\d{4,6}$/')
                    ->validationMessages(['regex' => 'PIN 4–6 haneli bir sayı olmalı.']),
                $pinInput('pin_confirmation', 'Yeni PIN (tekrar)')
                    ->same('pin')
                    ->validationMessages(['same' => 'İki PIN aynı değil.']),
            ])))
            ->action(function (array $data): void {
                CariLock::setPin($data['pin']);
                CariLock::touch();

                Notification::make()->success()->title('Cari PIN kaydedildi')->send();
            });
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
