<?php

namespace App\Filament\Resources\ActivityLogs\Pages;

use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use Filament\Resources\Pages\ListRecords;

class ListActivityLogs extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;

    public function getSubheading(): ?string
    {
        return 'Kim, hangi kanaldan, ne yaptı — silinen ve düzenlenen kayıtlar da burada kalır. Ayrıntı için satıra tıkla.';
    }
}
