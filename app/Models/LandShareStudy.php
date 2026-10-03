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
        'land_share_denominator',
        'method',
        'status',
        'notes',
    ];

    protected $casts = [
        'block_count'     => 'integer',
        'units_per_block' => 'integer',
        'land_share_denominator' => 'integer',
    ];

    /** Blok adları: A, B, C, ... (en fazla 26). */
    public static function blockNames(int $count): array
    {
        $count = min($count, 26);

        return $count > 0 ? array_map(fn ($i) => chr(65 + $i), range(0, $count - 1)) : [];
    }

    /**
     * "Blok Sayısı" ve blok başına BB sayılarına göre blokları (A, B, ...) ve
     * bağımsız bölümleri (1..N) otomatik oluşturur. Bloklar farklı sayıda BB
     * içerebilir; sayı verilmeyen blok, kayıtlı sayısını ya da units_per_block'u kullanır.
     *
     * Idempotent. Sayı azaltılınca fazla BB/blok yalnız BOŞSA silinir (atama ya
     * da arsa payı varsa korunur, veri kaybı olmaz) — korunanlar uyarı olarak döner.
     *
     * @param  array<string, int|string|null>  $unitCounts  blok adı => BB sayısı
     * @return array<int, string>  silinemeyip korunan BB/blok uyarıları
     */
    public function syncStructure(array $unitCounts = []): array
    {
        $names = self::blockNames((int) $this->block_count);
        if ($names === []) {
            return [];
        }

        $kept = [];

        foreach ($names as $i => $name) {
            $block = $this->blocks()->firstOrCreate(['name' => $name], ['sort' => $i]);

            $perBlock = (int) (filled($unitCounts[$name] ?? null)
                ? $unitCounts[$name]
                : ($block->planned_unit_count ?? $this->units_per_block));

            if ($perBlock <= 0) {
                continue;
            }

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

            // Sayı azaltıldıysa fazla BB'leri temizle (yalnız boş olanları).
            foreach ($block->sections()->get() as $section) {
                if (! ctype_digit((string) $section->bb_no) || (int) $section->bb_no <= $perBlock) {
                    continue;
                }
                if ($section->allocations()->exists() || $section->arsa_pay !== null) {
                    $kept[] = "{$name}-{$section->bb_no}";
                } else {
                    $section->delete();
                }
            }
        }

        // Blok sayısı azaltıldıysa fazla blokları temizle (yalnız boş olanları).
        foreach ($this->blocks()->whereNotIn('name', $names)->get() as $block) {
            $used = $block->sections()
                ->where(fn ($q) => $q->whereHas('allocations')->orWhereNotNull('arsa_pay'))
                ->exists();
            if ($used) {
                $kept[] = "{$block->name} Blok";
            } else {
                $block->sections()->delete();
                $block->delete();
            }
        }

        return $kept;
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
