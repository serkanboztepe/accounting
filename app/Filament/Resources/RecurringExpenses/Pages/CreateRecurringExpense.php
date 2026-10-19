<?php

namespace App\Filament\Resources\RecurringExpenses\Pages;

use App\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRecurringExpense extends CreateRecord
{
    protected static string $resource = RecurringExpenseResource::class;
}
