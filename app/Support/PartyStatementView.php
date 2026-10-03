<?php

namespace App\Support;

use App\Models\CompanySettings;
use App\Models\Party;
use App\Models\Project;

/**
 * Ekstre çıktısı (print.party-statement) için görünüm verisi — panel "Ekstre Yazdır" ve
 * WhatsApp PDF'i aynı veriyi kullansın (iki ayrı hesap olmasın).
 */
class PartyStatementView
{
    /**
     * @param  array{date_from?:string,date_to?:string,project_id?:int|string}  $filters
     */
    public static function data(Party $party, array $filters = []): array
    {
        $filters = array_filter($filters, fn ($v) => filled($v));

        return [
            'party'       => $party,
            'company'     => CompanySettings::current(),
            'statement'   => PartyStatement::build($party, $filters),
            'filters'     => $filters,
            'projectName' => ! empty($filters['project_id']) ? Project::find($filters['project_id'])?->name : null,
        ];
    }
}
