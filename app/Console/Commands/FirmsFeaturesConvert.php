<?php

namespace App\Console\Commands;

use App\Models\HubFirm;
use App\Support\FeatureSettings;
use App\Support\ModuleProfiles;
use Illuminate\Console\Command;

/**
 * Firmaları yeni özellik düzenine çevirir (plan 26.10, Faz B): bugünkü paket + ezmelerden çözülen modül listesi
 * firmanın SAKLI kutularına yazılır. Varsayılan deneme kipidir — yalnız "bugün → yeni" farkını basar, yazmaz.
 *
 * Beklenen farklar yalnız: çekler açılır (çekirdek), alış/ödeme açılır (çekirdek), Stok açık firmada Stok'la birlikte
 * gelenler açılır (ör. Ay Sonucu). Başka bir fark ya da paket adı değişirse HİÇBİR firma yazılmaz.
 */
class FirmsFeaturesConvert extends Command
{
    protected $signature = 'firms:features-convert {--write : Farklar beklenense gerçekten yaz (önce merkez yedeği alın)}';

    protected $description = 'Firmaları yeni özellik düzenine çevirir (varsayılan: deneme, yazmaz)';

    public function handle(): int
    {
        $plans = [];
        $unexpected = false;

        foreach (HubFirm::query()->whereNotNull('database')->orderBy('id')->get() as $firm) {
            $s = $firm->settingsWithDefaults();
            if (FeatureSettings::converted($s)) {
                $this->line("#{$firm->id} {$firm->name}: zaten yeni düzende — atlandı");

                continue;
            }

            $before = ModuleProfiles::resolve($s['profile'], $s['modules']);
            $proposal = FeatureSettings::fromModules($before);
            $label = $s['project_label'] ?? FeatureSettings::legacyPlaceLabel($s['profile']);
            $profile = FeatureSettings::deriveProfile($proposal['features'], $label);
            $settings = array_merge((array) $firm->settings, $proposal, [
                'modules' => [], 'project_label' => $label, 'profile' => $profile,
            ]);
            $diff = FeatureSettings::diff($before, FeatureSettings::toModules($settings));

            $on = array_keys(array_filter($proposal['features']));
            $this->newLine();
            $this->info("#{$firm->id} {$firm->name}");
            $this->line('  Kutular: ' . ($on ? implode(', ', array_map(fn ($k) => FeatureSettings::FEATURE_LABELS[$k], $on)) : '(yalnız çekirdek)')
                . " · Yer adı: {$label} · Paket: " . ($s['profile'] ?? '—') . ($profile !== $s['profile'] ? " → {$profile}" : ''));
            if ($profile !== $s['profile']) {
                $this->error('  ✗ BEKLENMEYEN: paket adı değişiyor (üslup/yardım metinleri değişir)');
                $unexpected = true;
            }
            foreach ($diff as $key => [$b, $a, $expected, $why]) {
                $line = sprintf('  %s %s: %s → %s — %s', $expected ? '•' : '✗', $key, $b ? 'açık' : 'kapalı', $a ? 'açık' : 'kapalı', $why);
                $expected ? $this->line($line) : $this->error($line);
                $unexpected = $unexpected || ! $expected;
            }
            if ($diff === [] && $profile === $s['profile']) {
                $this->line('  • fark yok');
            }

            $plans[] = [$firm, $settings];
        }

        $this->newLine();
        if ($unexpected) {
            $this->error('Beklenmeyen fark var — HİÇBİR firma yazılmadı.');

            return self::FAILURE;
        }
        if (! $this->option('write')) {
            $this->info(count($plans) . ' firma çevrilmeye hazır. Yazmak için: --write (önce merkez yedeği).');

            return self::SUCCESS;
        }

        foreach ($plans as [$firm, $settings]) {
            $firm->update(['settings' => $settings]);
        }
        $this->info(count($plans) . ' firma yeni düzene çevrildi.');

        return self::SUCCESS;
    }
}
