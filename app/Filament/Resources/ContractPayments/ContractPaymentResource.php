<?php

namespace App\Filament\Resources\ContractPayments;

use App\Filament\Resources\ContractPayments\Pages\CreateContractPayment;
use App\Filament\Resources\ContractPayments\Pages\EditContractPayment;
use App\Filament\Resources\ContractPayments\Pages\ListContractPayments;
use App\Filament\Resources\ContractPayments\Schemas\ContractPaymentForm;
use App\Filament\Resources\ContractPayments\Tables\ContractPaymentsTable;
use App\Models\ContractPayment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ContractPaymentResource extends Resource
{
    protected static ?string $model = ContractPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'İşlemler';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Sözleşme Ödemeleri';

    protected static ?string $modelLabel = 'Sözleşme Ödemesi';

    protected static ?string $pluralModelLabel = 'Sözleşme Ödemeleri';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return ContractPaymentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContractPaymentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContractPayments::route('/'),
            'create' => CreateContractPayment::route('/create'),
            'edit' => EditContractPayment::route('/{record}/edit'),
        ];
    }
}
