<?php

namespace App\Services\Whatsapp;

use App\Models\ExpenseCategory;
use App\Models\Party;
use App\Models\Project;

/**
 * AI'a verilecek eşleştirme bağlamı: mevcut projeler, cariler, gider kategorileri.
 * AI körlemesine tahmin etmesin — bu listeden id ile eşleştirsin.
 */
class ExpenseContext
{
    /**
     * Sistem prompt'una gömülecek kompakt liste bloğu.
     */
    public static function build(): string
    {
        $projects = Project::query()
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('name')
            ->get(['id', 'name', 'cadastral_parcel']);

        $parties = Party::query()->orderBy('name')->get(['id', 'name']);

        $categories = ExpenseCategory::query()->orderBy('name')->get(['id', 'name']);

        $lines = [];

        $lines[] = 'PROJELER (id: isim [ada/parsel]):';
        $lines[] = $projects->isEmpty()
            ? '  (kayıtlı proje yok)'
            : $projects->map(fn ($p) => sprintf('  %d: %s%s', $p->id, $p->name, $p->cadastral_parcel ? " [{$p->cadastral_parcel}]" : ''))->implode("\n");

        $lines[] = '';
        $lines[] = 'CARİLER (id: isim):';
        $lines[] = $parties->isEmpty()
            ? '  (kayıtlı cari yok)'
            : $parties->map(fn ($c) => sprintf('  %d: %s', $c->id, $c->name))->implode("\n");

        $lines[] = '';
        $lines[] = 'GİDER KATEGORİLERİ (id: isim):';
        $lines[] = $categories->isEmpty()
            ? '  (kayıtlı kategori yok)'
            : $categories->map(fn ($k) => sprintf('  %d: %s', $k->id, $k->name))->implode("\n");

        return implode("\n", $lines);
    }
}
