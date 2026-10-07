<x-filament-widgets::widget>
    @php($d = $this->getData())
    @php($m = fn ($v) => '₺' . \App\Support\Money::format((float) $v))

    <style>
        .pc-summary {
            --pc-card: #ffffff; --pc-border: #e4eae7; --pc-ink: #1b1915;
            --pc-muted: #6b7570; --pc-accent: #0B7350; --pc-good: #15803d; --pc-warn: #c2410c; --pc-pend: #b7791f;
            background: var(--pc-card); border: 1px solid var(--pc-border);
            border-radius: 14px; padding: 22px 24px; color: var(--pc-ink);
        }
        .dark .pc-summary {
            --pc-card: rgba(255,255,255,.03); --pc-border: rgba(255,255,255,.1); --pc-ink: #f2efe7;
            --pc-muted: #9aaba4; --pc-accent: #4CD39B; --pc-good: #4ade80; --pc-warn: #fb923c; --pc-pend: #e0b04a;
        }
        .pc-summary .tnum { font-variant-numeric: tabular-nums; }
        .pc-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 12px; }
        .pc-eyebrow { font-size: 11px; text-transform: uppercase; letter-spacing: .1em; color: var(--pc-accent); font-weight: 700; }
        .pc-hero { font-size: 32px; font-weight: 700; letter-spacing: -.02em; line-height: 1.1; margin-top: 3px; }
        .pc-break { font-size: 12.5px; color: var(--pc-muted); }
        .pc-break b { color: var(--pc-ink); font-weight: 600; }
        .pc-grid { margin-top: 20px; border-top: 1px solid var(--pc-border); display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0 24px; }
        .pc-cell { padding-top: 14px; }
        .pc-cell + .pc-cell { border-left: 1px solid var(--pc-border); padding-left: 24px; }
        .pc-k { font-size: 12.5px; color: var(--pc-muted); }
        .pc-v { font-size: 18px; font-weight: 700; margin-top: 2px; }
        .pc-sub { font-size: 11px; color: var(--pc-muted); margin-top: 3px; line-height: 1.3; }
        .pc-op { color: var(--pc-muted); font-weight: 400; margin-right: 3px; }
        .pc-total .pc-k, .pc-total .pc-v { color: var(--pc-accent); }
        .pc-foot { margin-top: 14px; font-size: 12px; color: var(--pc-muted); }
        .pc-foot b { color: var(--pc-ink); font-weight: 600; }
        .pc-warn-row { margin-top: 14px; padding: 10px 12px; border-radius: 10px; font-size: 12.5px; line-height: 1.4;
            background: rgba(194,65,12,.08); border: 1px solid rgba(194,65,12,.25); color: var(--pc-warn); }
        .pc-warn-row b { font-weight: 700; }
        @media (max-width: 640px) {
            .pc-grid { grid-template-columns: 1fr; }
            .pc-cell + .pc-cell { border-left: 0; border-top: 1px solid var(--pc-border); padding-left: 0; }
        }
    </style>

    <div class="pc-summary">
        <div class="pc-top">
            <div>
                <div class="pc-eyebrow">Genel Durum · Alım</div>
                <div class="pc-hero tnum">{{ $m($d['contracts_total']) }}</div>
            </div>
            <div class="pc-break tnum">{{ $d['active_projects'] }} aktif proje · Gerçek ödenen <b>{{ $m($d['cash_paid']) }}</b></div>
        </div>

        <div class="pc-grid">
            <div class="pc-cell">
                <div class="pc-k">Ödenecek Nakit Para</div>
                <div class="pc-v tnum" style="color: var(--pc-warn)">{{ $m($d['cash_due']) }}</div>
                <div class="pc-sub">Çeki yazılmamış, açık kısım</div>
            </div>
            <div class="pc-cell">
                <div class="pc-k"><span class="pc-op">+</span>Bekleyen Çek</div>
                <div class="pc-v tnum" style="color: var(--pc-pend)">{{ $m($d['pending_checks']) }}</div>
                <div class="pc-sub">Yazıldı, tahsil bekliyor{{ $d['pending_note'] }}</div>
            </div>
            <div class="pc-cell pc-total">
                <div class="pc-k"><span class="pc-op">=</span>Toplam Ödenecek</div>
                <div class="pc-v tnum">{{ $m($d['total_due']) }}</div>
                <div class="pc-sub">Nakit-açık + bekleyen çek</div>
            </div>
        </div>

        @if ($d['check_excess'] > 0.01)
            <div class="pc-warn-row">
                ⚠️ Yazılan çekler kayıtlı borcu <b>{{ $m($d['check_excess']) }}</b> aşıyor — ilgili sözleşmelerin tutarı eksik girilmiş olabilir, kontrol et.
            </div>
        @endif

        <div class="pc-foot tnum">Gerçek ödenen = nakit/EFT + tahsil edilmiş çek · Toplam çıkış = gerçek ödenen + {{ $m($d['expenses_total']) }} direkt gider = <b>{{ $m($d['total_cash_out']) }}</b></div>
    </div>
</x-filament-widgets::widget>
