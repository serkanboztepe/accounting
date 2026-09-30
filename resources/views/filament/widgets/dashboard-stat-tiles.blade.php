@php($d = $this->getData())
@php($m = fn ($v) => '₺' . \App\Support\Money::format((float) $v))

<div>
    <style>
        .dst { --dst-surface:#ffffff; --dst-border:#ebe2d3; --dst-ink:#221c13; --dst-muted:#8c8071;
            --dst-shadow: 0 1px 2px rgba(60,45,20,.04), 0 10px 26px -16px rgba(60,45,20,.12);
            display:grid; grid-template-columns:repeat(4,1fr); gap:14px; }
        .dark .dst { --dst-surface:#1d1811; --dst-border:rgba(255,255,255,.08); --dst-ink:#f4efe4; --dst-muted:#9a9080;
            --dst-shadow: 0 1px 2px rgba(0,0,0,.35), 0 12px 32px -18px rgba(0,0,0,.6); }
        .dst-tile { position:relative; overflow:hidden; background:var(--dst-surface); border:1px solid var(--dst-border);
            border-radius:16px; padding:18px; box-shadow:var(--dst-shadow); }
        .dst-tile::before { content:""; position:absolute; inset:0 0 auto 0; height:3px; background:var(--dst-accent); }
        .dst-row { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
        .dst-k { font-size:12.5px; color:var(--dst-muted); font-weight:600; }
        .dst-v { font-size:24px; font-weight:800; letter-spacing:-.02em; margin-top:7px; color:var(--dst-ink);
            font-variant-numeric:tabular-nums; }
        .dst-s { font-size:11.5px; color:var(--dst-muted); margin-top:5px; line-height:1.3; }
        .dst-ic { width:38px; height:38px; border-radius:11px; display:grid; place-items:center; flex:none;
            background:var(--dst-accent-soft); color:var(--dst-accent); }
        .t-proj  { --dst-accent:#b45309; --dst-accent-soft:#fdf3e4; }
        .t-cont  { --dst-accent:#7c3aed; --dst-accent-soft:#f0eafe; }
        .t-cash  { --dst-accent:#c2410c; --dst-accent-soft:#fbeae1; }
        .t-check { --dst-accent:#2563eb; --dst-accent-soft:#e8effe; }
        .dark .t-proj  { --dst-accent:#f5a524; --dst-accent-soft:rgba(245,165,36,.13); }
        .dark .t-cont  { --dst-accent:#a78bfa; --dst-accent-soft:rgba(167,139,250,.14); }
        .dark .t-cash  { --dst-accent:#fb923c; --dst-accent-soft:rgba(251,146,60,.13); }
        .dark .t-check { --dst-accent:#60a5fa; --dst-accent-soft:rgba(96,165,250,.13); }
        @media (max-width:900px) { .dst { grid-template-columns:repeat(2,1fr); } }
        @media (max-width:520px) { .dst { grid-template-columns:1fr; } }
    </style>

    <div class="dst">
        <div class="dst-tile t-proj">
            <div class="dst-row">
                <div>
                    <div class="dst-k">Aktif Proje</div>
                    <div class="dst-v">{{ $d['active_projects'] }}</div>
                    <div class="dst-s">{{ $d['project_names'] ?: 'Aktif proje yok' }}</div>
                </div>
                <div class="dst-ic"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V8l7-5 7 5v13M10 21v-5h4v5"/></svg></div>
            </div>
        </div>

        <div class="dst-tile t-cont">
            <div class="dst-row">
                <div>
                    <div class="dst-k">Sözleşme Toplamı</div>
                    <div class="dst-v">{{ $m($d['contracts_total']) }}</div>
                    <div class="dst-s">Aktif alım sözleşmeleri</div>
                </div>
                <div class="dst-ic"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/><path d="M9 13h6M9 17h4"/></svg></div>
            </div>
        </div>

        <div class="dst-tile t-cash">
            <div class="dst-row">
                <div>
                    <div class="dst-k">Ödenecek Nakit</div>
                    <div class="dst-v">{{ $m($d['cash_due']) }}</div>
                    <div class="dst-s">Çeki yazılmamış açık kısım</div>
                </div>
                <div class="dst-ic"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 7h20v10H2z"/><circle cx="12" cy="12" r="2.5"/></svg></div>
            </div>
        </div>

        <div class="dst-tile t-check">
            <div class="dst-row">
                <div>
                    <div class="dst-k">Bekleyen Çek</div>
                    <div class="dst-v">{{ $m($d['pending_checks']) }}</div>
                    <div class="dst-s">Yazıldı, tahsil bekliyor{{ $d['overdue_count'] > 0 ? ' · ' . $d['overdue_count'] . ' gecikmiş' : '' }}</div>
                </div>
                <div class="dst-ic"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v14H4z"/><path d="M4 10h16M8 15h4"/></svg></div>
            </div>
        </div>
    </div>
</div>
