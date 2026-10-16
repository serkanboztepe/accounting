<?php

namespace App\Models;

use App\Support\HubSignature;
use App\Support\ModuleProfiles;
use App\Tenancy\Tenancy;
use App\Tenancy\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Hub firması. İki tür:
 *  - Tek paneldeki firma (database dolu): veritabanı bu uygulamada, ayarları burada (settings).
 *  - Ayrı kurulumlu firma (url dolu): mesajlar HTTP ile o kuruluma iletilir (eski düzen / müşteri sunucusu).
 */
class HubFirm extends Model
{
    use UsesCentralConnection;

    protected $fillable = ['name', 'database', 'db_username', 'db_password', 'settings', 'url', 'secret', 'is_active'];

    protected $hidden = ['secret', 'db_password'];

    protected $casts = [
        'is_active'   => 'boolean',
        'settings'    => 'array',
        'db_password' => 'encrypted',
    ];

    /** Firma ayarları varsayılanları (hub ekranında boş bırakılanlar). */
    /** Hub'dan açılan YENİ firmada baştan açık gelen modüller (akıllı asistan, doğal konuşma, sabah haberi). */
    public const NEW_FIRM_MODULES = ['smart_assistant' => true, 'natural_voice' => true, 'proactive_alerts' => true];

    public const DEFAULT_SETTINGS = [
        'profile'             => null,  // mimar | muteahhit | trade | alacak_verecek (ModuleProfiles)
        'modules'             => [],    // anahtar => bool; olmayan = profile bırak
        'cari_lock'           => false,
        'cari_lock_minutes'   => 5,
        'check_reminder_days' => [3, 0],
        'project_label'       => null,  // "Proje" / "Şube" / serbest; boş = sektöre göre (Labels::project)
        'sector'              => null,  // işletme türü (config/sectors.php) — asistan karşısındakinin işini bilsin; paketten ayrı
        'business_note'       => null,  // tek satır işletme notu ("çoğunlukla vadeli çekle öder")
    ];

    protected static function booted(): void
    {
        static::creating(function (HubFirm $firm) {
            if ($firm->url) {
                $firm->secret = $firm->secret ?: Str::random(48);
                $firm->url = rtrim($firm->url, '/');
            }
        });
    }

    public function phones(): HasMany
    {
        return $this->hasMany(HubPhone::class);
    }

    public function messageLogs(): HasMany
    {
        return $this->hasMany(HubMessageLog::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(FirmUser::class);
    }

    /** Veritabanı bu uygulamada mı (tek panel), yoksa ayrı kurulumda mı? */
    public function isLocal(): bool
    {
        return filled($this->database);
    }

    /** @return array{profile: ?string, modules: array<string,bool>, cari_lock: bool, cari_lock_minutes: int, check_reminder_days: list<int>} */
    public function settingsWithDefaults(): array
    {
        $s = array_merge(self::DEFAULT_SETTINGS, array_filter((array) $this->settings, fn ($v) => $v !== null));

        return [
            'profile'             => $s['profile'] ?: null,
            'modules'             => array_map('boolval', array_filter((array) $s['modules'], fn ($v) => $v !== null)),
            'cari_lock'           => (bool) $s['cari_lock'],
            'cari_lock_minutes'   => max(1, (int) $s['cari_lock_minutes']),
            'check_reminder_days' => array_values(array_map('intval', (array) $s['check_reminder_days'])),
            'project_label'       => is_string($s['project_label'] ?? null) && trim($s['project_label']) !== '' ? trim($s['project_label']) : null,
            'sector'              => array_key_exists((string) ($s['sector'] ?? ''), (array) config('sectors')) ? $s['sector'] : null,
            'business_note'       => is_string($s['business_note'] ?? null) && trim($s['business_note']) !== '' ? mb_substr(trim($s['business_note']), 0, 300) : null,
        ];
    }

    /**
     * Hub "Modüller" penceresinden gelen form → settings. Modül alanları mod_<anahtar>:
     * '' = sektöre göre (kaydedilmez), '1' açık, '0' kapalı.
     */
    public function saveSettingsForm(array $data): void
    {
        $modules = [];
        foreach (ModuleProfiles::KEYS as $key) {
            $v = (string) ($data["mod_{$key}"] ?? '');
            if ($v !== '') {
                $modules[$key] = $v === '1';
            }
        }

        $days = array_filter(array_map('trim', explode(',', (string) ($data['check_reminder_days'] ?? ''))), 'strlen');

        $this->update(['settings' => [
            'profile'             => ($data['profile'] ?? null) ?: null,
            'modules'             => $modules,
            'cari_lock'           => (bool) ($data['cari_lock'] ?? false),
            'cari_lock_minutes'   => (int) (($data['cari_lock_minutes'] ?? null) ?: 5),
            'check_reminder_days' => array_values(array_unique(array_map('intval', $days))),
            'project_label'       => trim((string) ($data['project_label'] ?? '')) ?: null,
            'sector'              => array_key_exists((string) ($data['sector'] ?? ''), (array) config('sectors')) ? $data['sector'] : null,
            'business_note'       => mb_substr(trim((string) ($data['business_note'] ?? '')), 0, 300) ?: null,
        ]]);
    }

    /** Çek hatırlatması alacak numaralar (hub'da "Hatırlatma" işaretli). */
    public function reminderPhones(): array
    {
        return $this->phones()
            ->where('is_active', true)
            ->where('receives_reminders', true)
            ->pluck('phone')
            ->all();
    }

    /** Bir andan bu yana AI maliyeti (bakiye takibi). Tek panelde doğrudan; ulaşılamazsa null. */
    public function aiCostSince(\Illuminate\Support\Carbon $since): ?float
    {
        if (! $this->isLocal()) {
            return null;
        }
        try {
            return Tenancy::run($this, fn () => (float) UsageLog::where('type', UsageLog::TYPE_AI)
                ->where('created_at', '>=', $since)->sum('cost_usd'));
        } catch (Throwable) {
            return null;
        }
    }

    /** Ay özeti (AI + şablon maliyeti). Tek panelde doğrudan; ayrı kurulumda imzalı istek. Ulaşılamazsa null. */
    public function fetchUsage(string $month): ?array
    {
        if ($this->isLocal()) {
            try {
                return Tenancy::run($this, fn () => UsageLog::monthSummary($month));
            } catch (Throwable) {
                return null;
            }
        }

        $params = ['month' => $month];

        try {
            $response = Http::timeout(8)
                ->withHeaders(HubSignature::headers($params, (string) $this->secret))
                ->get(rtrim((string) $this->url, '/') . '/hub/usage', $params);

            return $response->successful() ? $response->json() : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function webhookUrl(): string
    {
        return rtrim((string) $this->url, '/') . '/whatsapp/webhook';
    }
}
