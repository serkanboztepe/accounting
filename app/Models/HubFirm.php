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
    public const DEFAULT_SETTINGS = [
        'profile'             => null,  // mimar | muteahhit | toptanci (ModuleProfiles)
        'modules'             => [],    // anahtar => bool; olmayan = profile bırak
        'cari_lock'           => false,
        'cari_lock_minutes'   => 5,
        'check_reminder_days' => [3, 0],
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
