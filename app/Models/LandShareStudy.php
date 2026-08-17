<?php

namespace App\Models;

use App\Services\LandShare\StudyData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LandShareStudy extends Model
{
    protected $fillable = [
        'project_id',
        'name',
        'ada',
        'parsel',
        'block_count',
        'units_per_block',
        'method',
        'status',
        'notes',
    ];

    protected $casts = [
        'block_count'     => 'integer',
        'units_per_block' => 'integer',
    ];

    /**
     * Üstte girilen "Blok Sayısı" ve "Blok Başına Daire" değerlerine göre
     * blokları (A, B, ...) ve bağımsız bölümleri (1..N) otomatik oluşturur.
     * Idempotent: eksikleri tamamlar, mevcut BB/atamaları asla silmez
     * (sayı azaltılsa bile veri kaybı olmaz).
     */
    public function syncStructure(): void
    {
        $blockCount = (int) $this->block_count;
        $perBlock = (int) $this->units_per_block;

        if ($blockCount <= 0 || $perBlock <= 0) {
            return;
        }

        for ($i = 0; $i < min($blockCount, 26); $i++) {
            $name = chr(65 + $i); // A, B, C, ...

            $block = $this->blocks()->firstOrCreate(
                ['name' => $name],
                ['sort' => $i, 'planned_unit_count' => $perBlock],
            );

            if ((int) $block->planned_unit_count !== $perBlock) {
                $block->update(['planned_unit_count' => $perBlock]);
            }

            $existing = $block->sections()->pluck('bb_no')->map(fn ($v) => (string) $v)->all();

            for ($n = 1; $n <= $perBlock; $n++) {
                if (! in_array((string) $n, $existing, true)) {
                    $block->sections()->create([
                        'bb_no' => (string) $n,
                        'type'  => 'daire',
                        'sort'  => $n,
                    ]);
                }
            }
        }
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function shareholders(): HasMany
    {
        return $this->hasMany(LandShareholder::class, 'study_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(LandShareGroup::class, 'study_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(LandBlock::class, 'study_id');
    }

    /**
     * Eloquent verisini hesap motorunun anladığı salt-veri yapısına çevirir.
     * Anahtarlar id bazlı: hissedar 'sh{id}', BB 'sec{id}'.
     */
    public function toStudyData(): StudyData
    {
        $this->loadMissing(['shareholders', 'groups', 'blocks.sections.allocations']);

        $shareholders = $this->shareholders->map(fn (LandShareholder $s) => [
            'key'           => 'sh' . $s->id,
            'name'          => $s->name,
            'current_pay'   => (int) $s->current_pay,
            'current_payda' => (int) $s->current_payda,
            'is_contractor' => (bool) $s->is_contractor,
            'group_key'     => $s->group_key,
        ])->all();

        $sections = [];
        $allocations = [];

        foreach ($this->blocks as $block) {
            foreach ($block->sections as $section) {
                $sections['sec' . $section->id] = [
                    'arsa_pay'   => $section->arsa_pay !== null ? (int) $section->arsa_pay : null,
                    'arsa_payda' => $section->arsa_payda !== null ? (int) $section->arsa_payda : null,
                ];

                foreach ($section->allocations as $alloc) {
                    $allocations[] = [
                        'section' => 'sec' . $section->id,
                        'holder'  => 'sh' . $alloc->shareholder_id,
                        'pay'     => (int) $alloc->pay,
                        'payda'   => (int) $alloc->payda,
                    ];
                }
            }
        }

        $groups = $this->groups
            ->mapWithKeys(fn (LandShareGroup $g) => [$g->group_key => (int) $g->unit_credits])
            ->all();

        return new StudyData($shareholders, $allocations, $sections, $groups);
    }
}
