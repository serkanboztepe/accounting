<?php

namespace App\Filament\Resources\RecurringExpenses\Pages;

use App\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use Filament\Resources\Pages\EditRecord;

class EditRecurringExpense extends EditRecord
{
    protected static string $resource = RecurringExpenseResource::class;
}
