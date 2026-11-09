<?php

namespace App\Services\Agent\Tools;

use App\Services\Whatsapp\ConversationLookup;
use App\Services\Whatsapp\RecordsQuery;
use Throwable;

/**
 * Ajanın OKUMA araçları (plan v5 §2): hiçbiri veri değiştirmez. Araç bir hata verirse ajana düz cümleyle döner (tur kopmaz).
 * Sıra ve tanımlar firmadan bağımsızdır (önbellek); yalnız sql_select firmanın salt-okur kullanıcısı varsa listeye girer.
 */
final class ReadTools
{
    /** @return list<array{name:string, description:string, input_schema:array}> */
    public static function definitions(): array
    {
        $records = RecordsQuery::tool();
        $records['name'] = 'records';
        unset($records['input_schema']['properties']['deep']); // eski asistanın model seçimi — ajanda yok

        $conversations = ConversationLookup::tool();
        $conversations['description'] = str_replace('query_records', 'records', $conversations['description']);
        $conversations['input_schema']['properties']['ref']['description'] = 'Kaydın ref\'i (records / party_overview sonucundan, ör. "ledger:12")';

        $tools = [
            FindParty::tool(),
            PartyOverview::tool(),
            $records,
            Report::tool(),
            $conversations,
        ];
        if (SqlSelect::available()) {
            $tools[] = SqlSelect::tool();
        }

        return $tools;
    }

    public static function has(string $name): bool
    {
        return in_array($name, array_column(self::definitions(), 'name'), true);
    }

    public static function run(string $name, array $input): array
    {
        try {
            return match ($name) {
                'find_party' => FindParty::run($input),
                'party_overview' => PartyOverview::run($input),
                'records' => RecordsQuery::run($input),
                'report' => Report::run($input),
                'search_conversations' => ConversationLookup::run($input),
                'sql_select' => SqlSelect::available() ? SqlSelect::run($input) : ['hata' => 'Bu araç bu firmada yok.'],
                default => ['hata' => 'Böyle bir okuma aracı yok: ' . $name],
            };
        } catch (Throwable $e) {
            report($e);

            return ['hata' => 'Araç çalışmadı (' . mb_substr($e->getMessage(), 0, 160) . '). Başka bir yolla dene ya da kullanıcıya bakamadığını söyle.'];
        }
    }
}
