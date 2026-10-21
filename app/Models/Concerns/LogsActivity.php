<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use App\Models\Party;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * İşlem geçmişine yazılan model: eklendi / düzenlendi / silindi kendiliğinden yazılır (ActivityLog).
 * Model anlatır: activityAction() ("Para aldı", "Ürün kartı"), activityDetails() ([etiket => değer], teknik alan YOK —
 * yalnız teknik alan değişirse satır yazılmaz), cari, tutar, özet.
 * - activitySkipped(): bu satır ayrı iş değil (belgenin cari satırı gibi) → hiç yazılmaz.
 * - activityParent(): alt satır (sözleşme kalemi, hisse satırı) → üst kayıt için bir kez "düzenlendi".
 * - activityAuto() false: eklenme/düzenlenme belgenin kendi kodunda yazılır (satış/alış kalemleri sonradan kurulur); silinme yine kendiliğinden.
 * - activityBeforeDelete(): veritabanı kendiliğinden silecekse (cascade) bağlı kayıtları önce yaz.
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(function (Model $m): void {
            if ($m->activitySkipped()) {
                return;
            }
            if ($parent = $m->activityParent()) {
                ActivityLog::touchParent($parent);
            } elseif ($m->activityAuto()) {
                ActivityLog::record($m, 'created');
            }
        });

        static::updated(function (Model $m): void {
            if ($m->activitySkipped()) {
                return;
            }
            if ($parent = $m->activityParent()) {
                ActivityLog::touchParent($parent);
            } elseif ($m->activityAuto()) {
                $old = $m->activityOriginal();
                ActivityLog::record($m, 'updated', $old->activityDetails(), beforeAmount: $old->activityAmount());
            }
        });

        // Silinmeden ÖNCE (deleting): bağlı çekler/iadeler modelin kendi deleting kancasında ya da veritabanında silinmeden
        // ayrıntıya girsin. Trait kancası modelin booted() kancalarından önce kaydolur, önce çalışır.
        static::deleting(function (Model $m): void {
            if ($m->activitySkipped()) {
                return;
            }
            if ($parent = $m->activityParent()) {
                ActivityLog::touchParent($parent);

                return;
            }
            $m->activityBeforeDelete();
            ActivityLog::record($m, 'deleted');
        });
    }

    /** Düzenlemeden önceki hal (updated olayında orijinal değerler henüz duruyor). */
    public function activityOriginal(): static
    {
        return (clone $this)->setRawAttributes($this->getRawOriginal(), true);
    }

    public function activityType(): string
    {
        return Str::snake(class_basename($this));
    }

    public function activityAuto(): bool
    {
        return true;
    }

    public function activitySkipped(): bool
    {
        return false;
    }

    public function activityParent(): ?Model
    {
        return null;
    }

    /** Silinmeden önce: veritabanının kendiliğinden sileceği bağlı kayıtları yaz (cari → hareketleri, satış → iadeleri…). */
    public function activityBeforeDelete(): void
    {
    }

    public function activityPartyId(): ?int
    {
        return isset($this->party_id) ? (int) $this->party_id : null;
    }

    public function activityPartyName(): ?string
    {
        return ActivityLog::name(Party::class, $this->activityPartyId());
    }

    public function activityAmount(): ?float
    {
        return null;
    }

    public function activitySummary(): ?string
    {
        return null;
    }

    abstract public function activityAction(): string;

    /** @return array<string, string> */
    abstract public function activityDetails(): array;
}
