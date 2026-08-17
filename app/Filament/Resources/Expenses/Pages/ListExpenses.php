<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->fillForm(function (): array {
                    $data = [
                        'expense_date'   => now()->toDateString(),
                        'payment_status' => 'paid',
                    ];

                    $projectId = $this->tableFilters['project_id']['value'] ?? null;
                    if ($projectId) {
                        $data['project_id'] = $projectId;
                    }

                    return $data;
                }),
        ];
    }
}
