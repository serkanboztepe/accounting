<?php

namespace App\Services\Whatsapp;

use App\Models\Expense;
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
        $projects = ! config('modules.projects') ? collect() : Project::query()
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('name')
            ->get(['id', 'name', 'cadastral_parcel']);

        $parties = Party::query()->orderBy('name')->get(['id', 'name']);

        $categories = ExpenseCategory::query()->orderBy('name')->get(['id', 'name']);

        $lines = [];

        if (config('modules.projects')) {
            $lines[] = 'PROJELER (id: isim [ada/parsel]):';
            $lines[] = $projects->isEmpty()
                ? '  (kayıtlı proje yok)'
                : $projects->map(fn ($p) => sprintf('  %d: %s%s', $p->id, $p->name, $p->cadastral_parcel ? " [{$p->cadastral_parcel}]" : ''))->implode("\n");
        } else {
            $lines[] = 'PROJE KULLANILMIYOR: bu işletmede proje yok — project_id ve project_name HER ZAMAN null, proje sorma.';
        }

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

        // Ödenmemiş giderler: "kirayı ödedim" yeni gider açıp aynı kirayı ikinci kez saymasın — AI
        // ödemeyi buradaki açık borçla eşleştirir (settles_expense_id), kullanıcı teyit eder.
        if (config('modules.expenses')) {
            $open = Expense::query()
                ->whereIn('payment_status', ['unpaid', 'partial'])
                ->with('party:id,name')
                ->latest('expense_date')->latest('id')
                ->take(25)
                ->get(['id', 'expense_date', 'amount', 'description', 'party_id']);

            $lines[] = '';
            $lines[] = 'ÖDENMEMİŞ GİDERLER (id: tarih — açıklama — tutar [cari]):';
            $lines[] = $open->isEmpty()
                ? '  (ödenmemiş gider yok)'
                : $open->map(fn (Expense $e) => sprintf('  %d: %s — %s — %s%s', $e->id, $e->expense_date->format('Y-m-d'),
                    $e->description ?: 'gider', (float) $e->amount, $e->party ? " [{$e->party->name}]" : ''))->implode("\n");
        }

        return implode("\n", $lines);
    }
}
