<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * İşlem geçmişi satırı (firma veritabanı): kullanıcının yaptığı her iş, kanalıyla. Yazılır, hiç değişmez/silinmez.
 * Modeller App\Models\Concerns\LogsActivity ile kendilerini anlatır (etiket, ayrıntılar, cari); bu sınıf satırı yazar.
 */
class ActivityLog extends Model
{
    public const EVENTS = ['created' => 'Eklendi', 'updated' => 'Düzenlendi', 'deleted' => 'Silindi', 'existing' => 'Önceden vardı'];

    public const PAYMENT_TYPES = ['cash' => 'Nakit', 'eft' => 'EFT', 'bank_transfer' => 'Havale / EFT', 'check' => 'Çek',
        'promissory_note' => 'Senet', 'card' => 'Kart', 'other' => 'Diğer'];

    public const CHANNELS = ['panel' => 'Panel', 'whatsapp' => 'WhatsApp', 'support' => 'Destek', 'system' => 'Sistem'];

    protected $fillable = ['happened_at', 'event', 'action', 'subject_type', 'subject_id', 'party_id', 'party_name', 'amount', 'old_amount',
        'summary', 'details', 'changes', 'channel', 'actor', 'note'];

    protected $casts = ['happened_at' => 'datetime', 'details' => 'array', 'changes' => 'array', 'amount' => 'decimal:2', 'old_amount' => 'decimal:2'];

    /** WhatsApp isteğinde webhook ayarlar: ['whatsapp', 'Emre (0532 059 11 24)']. Firma verisi değil, yalnız kim yazıyor. */
    private static ?array $via = null;

    /** Bu istekte "düzenlendi" yazılan üst kayıtlar (alt satır başına ayrı satır olmasın — hisse dağılımı, sözleşme kalemi). */
    private static array $touched = [];

    public static function via(string $channel, string $actor): void
    {
        self::$via = [$channel, $actor];
    }

    public static function clearVia(): void
    {
        self::$via = null;
    }

    /** @return array{0: string, 1: ?string} kanal + kişi */
    public static function currentActor(): array
    {
        if (self::$via) {
            return self::$via;
        }
        if (! app()->runningInConsole() && request()->is('hub', 'hub/*')) {
            return ['support', auth('hub')->user()?->name ?? 'Destek'];
        }
        // Giriş yapmış panel kullanıcısı (tinker/zamanlanmış görevde oturum yok).
        if ($user = auth()->user()) {
            return ['panel', $user->name];
        }
        if (! app()->runningInConsole()) {
            return ['system', null];
        }
        // Sunucuda elle çalıştırılan komut (tinker) = bizim düzeltmemiz; zamanlanmış görevler = sistem.
        $command = $_SERVER['argv'][1] ?? '';

        return str_contains($command, 'tinker') ? ['support', 'Destek'] : ['system', null];
    }

    /**
     * Modelin yaptığı işi yaz. $before: düzenlemeden önceki ayrıntılar (değişen alanlar bundan çıkar).
     * Ayrıntılarda değişen bir şey yoksa (yalnız teknik alan değiştiyse) satır yazılmaz.
     */
    public static function record(Model $model, string $event, ?array $before = null, ?string $note = null, ?float $beforeAmount = null): ?self
    {
        $details = $model->activityDetails();
        $changes = null;
        if ($event === 'updated') {
            $changes = [];
            foreach (array_unique(array_merge(array_keys($before ?? []), array_keys($details))) as $label) {
                $old = $before[$label] ?? null;
                $new = $details[$label] ?? null;
                if ((string) $old !== (string) $new) {
                    $changes[$label] = [$old, $new];
                }
            }
            if ($changes === []) {
                return null;
            }
        }

        [$channel, $actor] = self::currentActor();
        $partyId = $model->activityPartyId();
        $amount = $model->activityAmount();

        $log = static::create([
            'happened_at'  => now(),
            'event'        => $event,
            'action'       => $model->activityAction(),
            'subject_type' => $model->activityType(),
            'subject_id'   => $model->getKey(),
            'party_id'     => $partyId,
            'party_name'   => $partyId ? $model->activityPartyName() : null,
            'amount'       => $amount,
            'old_amount'   => $event === 'updated' && $beforeAmount !== null && round($beforeAmount, 2) !== round((float) $amount, 2) ? $beforeAmount : null,
            'summary'      => mb_substr((string) $model->activitySummary(), 0, 255) ?: null,
            'details'      => $details,
            'changes'      => $changes ?: null,
            'channel'      => $channel,
            'actor'        => $actor,
            'note'         => $note,
        ]);
        self::$touched[self::touchKey($model)] = true;

        return $log;
    }

    /** Alt satır değişti (sözleşme kalemi, hisse dağılımı satırı…) → üst kayıt için bu istekte bir kez "düzenlendi". */
    public static function touchParent(?Model $parent, ?string $note = null): void
    {
        if (! $parent || ! $parent->exists || isset(self::$touched[self::touchKey($parent)])) {
            return;
        }
        self::$touched[self::touchKey($parent)] = true;
        [$channel, $actor] = self::currentActor();
        $partyId = $parent->activityPartyId();
        static::create([
            'happened_at'  => now(),
            'event'        => 'updated',
            'action'       => $parent->activityAction(),
            'subject_type' => $parent->activityType(),
            'subject_id'   => $parent->getKey(),
            'party_id'     => $partyId,
            'party_name'   => $partyId ? $parent->activityPartyName() : null,
            'amount'       => $parent->activityAmount(),
            'summary'      => mb_substr((string) $parent->activitySummary(), 0, 255) ?: null,
            'details'      => $parent->activityDetails(),
            'channel'      => $channel,
            'actor'        => $actor,
            'note'         => $note,
        ]);
    }

    /**
     * Olay tetiklemeyen toplu silmeden ÖNCE her satırı "silindi" yaz, sonra sil (sorgu ile silme modeli atlıyordu).
     * Yazılmaması gereken parçalar (belgenin cari satırı gibi) modelin activitySkipped()'iyle elenir.
     */
    public static function deleteQuery(Builder|Relation $query, ?string $note = null): int
    {
        foreach ((clone $query)->get() as $row) {
            if (method_exists($row, 'activitySkipped') && ! $row->activitySkipped()) {
                self::record($row, 'deleted', note: $note);
            }
        }

        return $query->delete();
    }

    /** Toplu güncellemeden önce/sonra her satırı yaz (Takvim'den hatırlatma iptali gibi). */
    public static function updateQuery(Builder|Relation $query, array $values): int
    {
        $rows = (clone $query)->get();
        $count = $query->update($values);
        foreach ($rows as $row) {
            $before = $row->activityDetails();
            $row->forceFill($values);
            self::record($row, 'updated', $before);
        }

        return $count;
    }

    private static function touchKey(Model $model): string
    {
        return $model->getConnection()->getDatabaseName() . ':' . $model::class . ':' . $model->getKey();
    }

    // ---- Ayrıntı biçimleri (modeller kullanır) ----

    public static function money(mixed $v): ?string
    {
        return $v === null || $v === '' ? null : Money::format((float) $v) . ' ₺';
    }

    public static function date(mixed $v): ?string
    {
        if (blank($v)) {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($v)->format('d.m.Y');
    }

    public static function yes(mixed $v): ?string
    {
        return $v === null ? null : ($v ? 'Evet' : 'Hayır');
    }

    /** Boş değerleri at — ayrıntı listesi kısa kalsın. */
    public static function compact(array $details): array
    {
        return array_filter($details, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    public static function name(string $class, mixed $id, string $column = 'name'): ?string
    {
        if (! $id) {
            return null;
        }
        // Global scope'suz (satış sözleşmesi 'purchase' scope'u ile gizli) — silinmiş kayıtta '#id'.
        return $class::withoutGlobalScopes()->whereKey($id)->value($column) ?? ('#' . $id);
    }

    public function eventLabel(): string
    {
        return self::EVENTS[$this->event] ?? $this->event;
    }

    public function channelLabel(): string
    {
        $label = self::CHANNELS[$this->channel] ?? '—';

        return $this->actor ? $label . ' · ' . $this->actor : $label;
    }
}
