<x-filament-widgets::widget>
    @php($d = $this->getData())
    @php($m = fn ($v) => '₺' . \App\Support\Money::format((float) $v))

    @if (! empty($d))
    <style>
        .cs-summary {
            --cs-card: #ffffff; --cs-border: #e9e4db; --cs-ink: #1b1915;
            --cs-muted: #8b8474; --cs-accent: #b45309; --cs-good: #15803d; --cs-warn: #c2410c;
            background: var(--cs-card); border: 1px solid var(--cs-border);
            border-radius: 14px; padding: 22px 24px; color: var(--cs-ink);
        }
        .dark .cs-summary {
            --cs-card: rgba(255,255,255,.03); --cs-border: rgba(255,255,255,.1); --cs-ink: #f2efe7;
            --cs-muted: #9a9280; --cs-accent: #f5a524; --cs-good: #4ade80; --cs-warn: #fb923c;
        }
        .cs-summary .tnum { font-variant-numeric: tabular-nums; }
        .cs-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 12px; }
        .cs-eyebrow { font-size: 11px; text-transform: uppercase; letter-spacing: .1em; color: var(--cs-accent); font-weight: 700; }
        .cs-hero { font-size: 32px; font-weight: 700; letter-spacing: -.02em; line-height: 1.1; margin-top: 3px; }
        .cs-break { font-size: 12.5px; color: var(--cs-muted); text-align: right; }
        .cs-break b { color: var(--cs-ink); font-weight: 600; }
        .cs-grid { margin-top: 20px; border-top: 1px solid var(--cs-border); display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0 24px; }
        .cs-cell { padding-top: 14px; }
        .cs-cell + .cs-cell { border-left: 1px solid var(--cs-border); padding-left: 24px; }
        .cs-k { font-size: 12.5px; color: var(--cs-muted); }
        .cs-v { font-size: 18px; font-weight: 700; margin-top: 2px; }
        .cs-sub { font-size: 11px; color: var(--cs-muted); margin-top: 3px; line-height: 1.35; }
        .cs-sub b { color: var(--cs-ink); font-weight: 600; }
        .cs-foot { margin-top: 14px; font-size: 12px; color: var(--cs-muted); }
        @media (max-width: 640px) {
            .cs-grid { grid-template-columns: 1fr; }
            .cs-cell + .cs-cell { border-left: 0; border-top: 1px solid var(--cs-border); padding-left: 0; }
            .cs-break { text-align: left; }
        }
    </style>

    <div class="cs-summary">
        <div class="cs-top">
            <div>
                <div class="cs-eyebrow">Sözleşme{{ $d['type_label'] ? ' · ' . $d['type_label'] : '' }}</div>
                <div class="cs-hero tnum">{{ $m($d['total']) }}</div>
            </div>
            <div class="cs-break tnum">
                @if ($d['party_name']) <b>{{ $d['party_name'] }}</b> · @endif
                {{ $d['is_manual_total'] ? 'Manuel götürü bedel' : 'Kalemlerden hesaplandı' }}
            </div>
        </div>

        <div class="cs-grid">
            {{-- Teslimat / Hakediş --}}
            <div class="cs-cell">
                <div class="cs-k">{{ $d['is_sub'] ? 'Hakediş Tutarı' : 'Teslimat Tutarı' }}</div>
                <div class="cs-v tnum">{{ $m($d['delivered']) }}</div>
                @if ($d['delivery_diff'] > 0.005)
                    <div class="cs-sub" style="color: var(--cs-warn)">⚠ Aşım (fazla {{ $d['is_sub'] ? 'hakediş' : 'teslimat' }}): <b>+{{ $m($d['delivery_diff']) }}</b></div>
                @else
                    <div class="cs-sub">Kalan ({{ $d['is_sub'] ? 'hakediş' : 'teslimat' }}): <b>{{ $m(-$d['delivery_diff']) }}</b></div>
                @endif
            </div>

            {{-- Ödenen --}}
            <div class="cs-cell">
                <div class="cs-k">Ödenen</div>
                <div class="cs-v tnum">{{ $m($d['paid']) }}</div>
                <div class="cs-sub">
                    @if ($d['due_for_delivered'] > 0.005)
                        Teslim alınana göre borç: <b>{{ $m($d['due_for_delivered']) }}</b>
                    @elseif ($d['due_for_delivered'] < -0.005)
                        <span style="color: var(--cs-warn)">⚠ Teslim alınana göre fazla ödeme: <b>+{{ $m(-$d['due_for_delivered']) }}</b></span>
                    @else
                        Teslim alınana göre borç: <b>₺0,00</b>
                    @endif
                    <br>
                    @if ($d['contract_remaining'] > 0.005)
                        Sözleşmeye göre kalan: <b>{{ $m($d['contract_remaining']) }}</b>
                    @elseif ($d['contract_remaining'] < -0.005)
                        <span style="color: var(--cs-warn)">⚠ Sözleşmeye göre fazla ödeme: <b>+{{ $m(-$d['contract_remaining']) }}</b></span>
                    @else
                        Sözleşmeye göre kalan: <b>₺0,00</b>
                    @endif
                </div>
            </div>

            {{-- Net Durum --}}
            <div class="cs-cell">
                @if ($d['due_for_delivered'] > 0.005)
                    <div class="cs-k">Net Durum — Borç</div>
                    <div class="cs-v tnum" style="color: var(--cs-warn)">{{ $m($d['due_for_delivered']) }}</div>
                    <div class="cs-sub">Bu cariye ödenecek (teslim alınana göre)</div>
                @elseif ($d['due_for_delivered'] < -0.005)
                    <div class="cs-k">Net Durum — Alacak</div>
                    <div class="cs-v tnum" style="color: var(--cs-good)">{{ $m(-$d['due_for_delivered']) }}</div>
                    <div class="cs-sub">Fazla ödeme — cariden alacaklısın</div>
                @else
                    <div class="cs-k">Net Durum</div>
                    <div class="cs-v tnum" style="color: var(--cs-good)">₺0,00</div>
                    <div class="cs-sub">Kapandı — teslim alınan tümüyle ödendi</div>
                @endif
            </div>
        </div>

        <div class="cs-foot tnum">
            Teslim alınana göre borç = teslimat − ödenen · Sözleşmeye göre kalan = sözleşme − ödenen
        </div>
    </div>
    @endif
</x-filament-widgets::widget>
