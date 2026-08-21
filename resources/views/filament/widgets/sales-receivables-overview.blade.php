<x-filament-widgets::widget>
    @php($d = $this->getData())
    @php($m = fn ($v) => '₺' . \App\Support\Money::format((float) $v))

    <style>
        .sr-summary {
            --sr-card: #ffffff; --sr-border: #e3ebe4; --sr-ink: #12201a;
            --sr-muted: #6f8579; --sr-accent: #15803d; --sr-warn: #c2410c; --sr-pend: #b7791f;
            background: var(--sr-card); border: 1px solid var(--sr-border);
            border-radius: 14px; padding: 22px 24px; color: var(--sr-ink);
        }
        .dark .sr-summary {
            --sr-card: rgba(74,222,128,.04); --sr-border: rgba(74,222,128,.18); --sr-ink: #eaf5ee;
            --sr-muted: #8fae9c; --sr-accent: #4ade80; --sr-warn: #fb923c; --sr-pend: #e0b04a;
        }
        .sr-summary .tnum { font-variant-numeric: tabular-nums; }
        .sr-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 12px; }
        .sr-eyebrow { font-size: 11px; text-transform: uppercase; letter-spacing: .1em; color: var(--sr-accent); font-weight: 700; }
        .sr-hero { font-size: 32px; font-weight: 700; letter-spacing: -.02em; line-height: 1.1; margin-top: 3px; }
        .sr-break { font-size: 12.5px; color: var(--sr-muted); }
        .sr-break b { color: var(--sr-ink); font-weight: 600; }
        .sr-grid { margin-top: 20px; border-top: 1px solid var(--sr-border); display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0 24px; }
        .sr-cell { padding-top: 14px; }
        .sr-cell + .sr-cell { border-left: 1px solid var(--sr-border); padding-left: 24px; }
        .sr-k { font-size: 12.5px; color: var(--sr-muted); }
        .sr-v { font-size: 18px; font-weight: 700; margin-top: 2px; }
        .sr-foot { margin-top: 14px; font-size: 12px; color: var(--sr-muted); }
        @media (max-width: 640px) {
            .sr-grid { grid-template-columns: 1fr; }
            .sr-cell + .sr-cell { border-left: 0; border-top: 1px solid var(--sr-border); padding-left: 0; }
        }
    </style>

    <div class="sr-summary">
        <div class="sr-top">
            <div>
                <div class="sr-eyebrow">Satış / Alacak</div>
                <div class="sr-hero tnum">{{ $m($d['sales_total']) }}</div>
            </div>
            <div class="sr-break tnum">{{ $d['count'] }} satış sözleşmesi · maliyete dahil <b>değil</b></div>
        </div>

        <div class="sr-grid">
            <div class="sr-cell">
                <div class="sr-k">Tahsil Edilen</div>
                <div class="sr-v tnum" style="color: var(--sr-accent)">{{ $m($d['collected']) }}</div>
            </div>
            <div class="sr-cell">
                <div class="sr-k">Kalan Alacak</div>
                <div class="sr-v tnum" style="color: var(--sr-warn)">{{ $m($d['remaining']) }}</div>
            </div>
            <div class="sr-cell">
                <div class="sr-k">Bekleyen Çek</div>
                <div class="sr-v tnum" style="color: var(--sr-pend)">{{ $m($d['pending_checks']) }}</div>
            </div>
        </div>

        <div class="sr-foot tnum">Tahsil Edilen = nakit/EFT + tahsil edilmiş çek · Kalan Alacak = satış tutarı − tahsil edilen · Kârlılık (Brüt) proje bazında Proje Raporu'nda</div>
    </div>
</x-filament-widgets::widget>
