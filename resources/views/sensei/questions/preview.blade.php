<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pratinjau soal · {{ config('app.brand') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=4">
    <script>document.documentElement.classList.add('js')</script>
    <style>
        /* Tambahan khusus pratinjau sensei; tampilan soal memakai kelas yang sama dengan halaman ujian peserta. */
        .preview-banner { background: var(--warn-bg); color: #9a5a17; border-bottom: 1px solid #f4dcb8; padding: 8px 24px; font-size: 12.5px; display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: center; }
        .preview-banner a { color: inherit; font-weight: 700; }
        .sensei-meta { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin: -6px 0 14px; font-size: 11.5px; }
        .sensei-meta a { font-weight: 700; }
        .hide-meta .sensei-meta { display: none; }
        .choice.is-key { border-color: var(--green); background: var(--ok-bg); }
        .choice.is-key .lbl { background: var(--green); border-color: var(--green); color: #fff; }
        .choice.is-wrong { border-color: var(--sakura-deep); background: var(--err-bg); }
        .key-box { display: none; margin-top: 16px; }
        .key-box.open { display: block; }
        .check-btn { margin-top: 14px; }
        .q-grid a.checked-ok { background: var(--ok-bg); border-color: var(--green); color: var(--green); }
        .q-grid a.checked-bad { background: var(--err-bg); border-color: var(--sakura-deep); color: var(--sakura-deep); }
        .q-grid a.warn::before { content: "!"; position: absolute; top: -6px; left: -4px; width: 14px; height: 14px; border-radius: 50%; background: var(--sakura-deep); color: #fff; font-size: 9px; display: flex; align-items: center; justify-content: center; }
        .side-tools { display: grid; gap: 8px; margin-top: 4px; }
        .side-tools label { font-size: 12.5px; display: flex; gap: 8px; align-items: center; margin: 0; cursor: pointer; }
        .preview-empty { padding: 60px 24px; text-align: center; }
    </style>
</head>
<body>
@php
    $letters = ['A', 'B', 'C', 'D'];
    $total = $questions->count();
@endphp
<div class="exam-page">
    <div class="exam-topbar">
        <div class="brand"><img src="{{ asset('images/logo-160.png') }}" alt="">{{ config('app.brand') }}</div>
        <div class="user-chip">
            <div class="avatar" aria-hidden="true">P</div>
            <div class="txt"><p>Pratinjau peserta</p><span>{{ auth()->user()->name }}</span></div>
        </div>
    </div>

    <div class="preview-banner">
        <span>👁 Mode pratinjau: tampilan sama dengan yang dilihat peserta saat ujian. Jawaban <strong>tidak disimpan</strong>, tidak ada batas waktu, dan pilihan jawaban tidak diacak.</span>
        <a href="{{ $backUrl }}">← Kembali ke Pelatihan Soal</a>
    </div>

    @if ($total === 0)
        <div class="preview-empty">
            <h1>Tidak ada soal</h1>
            <p class="muted">Tidak ada soal yang cocok dengan filter. Ubah filter di Pelatihan Soal lalu coba lagi.</p>
            <a class="btn secondary" href="{{ $backUrl }}">Kembali</a>
        </div>
    @else
    <div class="ujian-head">
        <div>
            <h1>Pratinjau soal</h1>
            <span>{{ $total }} soal{{ $truncated ? ' (100 pertama; persempit filter untuk melihat sisanya)' : '' }}</span>
            <div class="section-steps">
                @foreach ($questions->groupBy('section_id') as $group)
                    @php $sec = $group->first()->section; @endphp
                    <span>{{ $sec->level->testType->code }} {{ $sec->level->code }} · {{ $sec->name }} ({{ $group->count() }})</span>
                @endforeach
            </div>
        </div>
        <div class="timer-chip" aria-label="Tanpa batas waktu">⏱ Pratinjau</div>
    </div>

    <div class="ujian-shell" id="preview">
        <div class="q-area">
            @foreach ($questions as $n => $q)
                @php
                    $passage = $q->passage;
                    $isEssay = $q->type->value === 'essay';
                    $key = $q->options->first(fn ($o) => (bool) $o->is_correct);
                    $problems = array_filter([
                        ! $isEssay && ! $key ? 'belum ada kunci' : null,
                        ! $isEssay && $q->options->count() < 2 ? 'pilihan kurang dari 2' : null,
                        $q->type->value === 'listening' && ! $q->audio_path && ! $passage?->audio_path ? 'audio belum ada' : null,
                    ]);
                @endphp
                <section @class(['q-card', 'current' => $n === $start]) data-index="{{ $n }}" aria-label="Soal {{ $n + 1 }}">
                    <div class="q-head">
                        <span class="q-no">Soal No. {{ $n + 1 }} dari {{ $total }}@if ($q->mondai) · {{ $q->mondai }}@endif</span>
                        <span><label class="flag-toggle"><input type="checkbox" data-flag> ragu-ragu</label></span>
                    </div>

                    <div class="sensei-meta">
                        <span class="badge">#{{ $q->id }}</span>
                        <span class="badge">{{ $q->section->level->testType->code }} {{ $q->section->level->code }} · {{ $q->section->name }}</span>
                        <span class="badge">{{ $types[$q->type->value] ?? $q->type->value }}</span>
                        @include('sensei.questions._status', ['status' => $q->status->value])
                        <span @class(['badge', 'warn' => $q->usage === 'test', 'info' => $q->usage === 'both'])>{{ $q->usageLabel() }}</span>
                        @foreach ($problems as $p)<span class="badge err">⚠ {{ $p }}</span>@endforeach
                        <a href="{{ route('sensei.questions.edit', $q) }}">Edit soal</a>
                    </div>

                    @if ($passage)
                        <div class="passage" lang="ja">
                            @if ($passage->title)<div class="muted" style="font-size:.85rem">{{ $passage->title }}</div>@endif
                            @if ($passage->image_path)<img class="q-img" src="{{ route('media', $passage->image_path) }}" alt="">@endif
                            @if ($passage->content)@jp($passage->content)@endif
                            @if ($passage->audio_path)@include('sensei.questions._preview_audio', ['path' => $passage->audio_path])@endif
                        </div>
                    @endif

                    @if ($q->image_path)<img class="q-img" src="{{ route('media', $q->image_path) }}" alt="">@endif
                    @if ($q->audio_path)@include('sensei.questions._preview_audio', ['path' => $q->audio_path])@endif
                    @if ($q->stem)<p class="q-stem" lang="ja">@jp($q->stem)</p>@endif

                    @if ($isEssay)
                        <textarea data-essay rows="4" lang="ja" maxlength="2000" placeholder="Tulis jawaban di sini" aria-label="Jawaban soal {{ $n + 1 }}"></textarea>
                    @else
                        <div class="choices" role="radiogroup" aria-label="Pilihan soal {{ $n + 1 }}">
                            @foreach ($q->options->sortBy('label')->values() as $pos => $opt)
                                <label class="choice" data-key="{{ $opt->is_correct ? 1 : 0 }}">
                                    <input type="radio" name="preview-{{ $q->id }}" value="{{ $opt->id }}">
                                    <span class="lbl">{{ $letters[$pos] ?? $pos + 1 }}</span>
                                    <span lang="ja">
                                        @if ($opt->image_path)<img src="{{ route('media', $opt->image_path) }}" alt="" style="max-height:90px;display:block">@endif
                                        @jp($opt->content)
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif

                    <button type="button" class="btn secondary small check-btn" data-check>✔ Cek jawaban</button>
                    <div class="key-box alert info" data-keybox>
                        <div data-verdict style="font-weight:700;margin-bottom:4px"></div>
                        @if ($isEssay)
                            <div><strong>Contoh jawaban:</strong> <span lang="ja">{{ $q->answer_reference ?: '–' }}</span> <small class="muted">(esai dinilai manual oleh sensei)</small></div>
                        @elseif ($key)
                            @php $keyPos = $q->options->sortBy('label')->values()->search(fn ($o) => $o->id === $key->id); @endphp
                            <div><strong>Kunci:</strong> {{ $letters[$keyPos] ?? '?' }} <small class="muted">(pilihan {{ $key->label }})</small></div>
                        @else
                            <div class="error-text"><strong>Kunci belum diisi.</strong> Soal ini tidak akan diambil ujian.</div>
                        @endif
                        @if ($q->type->value === 'star' && ($q->star_position || $q->correct_order))
                            <div><strong>★</strong> posisi {{ $q->star_position ?: '–' }} · urutan benar {{ $q->correct_order ?: '–' }}</div>
                        @endif
                        @if ($q->explanation)<div style="margin-top:6px"><strong>Pembahasan:</strong> <span lang="ja">@jp($q->explanation)</span></div>@endif
                    </div>

                    <div class="q-nav-btns js-only">
                        <button type="button" class="nbtn prev" data-go="{{ $n - 1 }}" @disabled($n === 0)>‹ Sebelumnya</button>
                        @if ($n < $total - 1)
                            <button type="button" class="nbtn next" data-go="{{ $n + 1 }}">Selanjutnya ›</button>
                        @endif
                    </div>
                </section>
            @endforeach
        </div>

        <aside class="q-side">
            <h2>Navigasi Soal</h2>
            <div class="q-grid">
                @foreach ($questions as $n => $q)
                    @php $bad = $q->type->value !== 'essay' && ! $q->options->contains(fn ($o) => (bool) $o->is_correct); @endphp
                    <a href="#" data-nav="{{ $n }}" @class(['current' => $n === $start, 'warn' => $bad]) aria-label="Soal {{ $n + 1 }}">{{ $n + 1 }}</a>
                @endforeach
            </div>
            <div class="legend">
                <span><i class="sw cur"></i>Sedang dilihat</span>
                <span><i class="sw ans"></i>Sudah dijawab</span>
                <span><i class="sw"></i>Belum dijawab</span>
                <span><i class="sw flag"></i>Ragu-ragu</span>
            </div>
            <div class="side-tools">
                <label><input type="checkbox" id="show-keys"> Tampilkan kunci &amp; pembahasan semua soal</label>
                <label><input type="checkbox" id="hide-meta"> Sembunyikan info sensei (tampilan peserta murni)</label>
            </div>
            <a class="btn-finish" href="{{ $backUrl }}" style="display:block;text-align:center;text-decoration:none;margin-top:16px">Selesai pratinjau</a>
            <p class="muted" style="font-size:11.5px;margin-top:10px">Tanda <strong>!</strong> pada nomor = soal belum punya kunci. Gunakan ← → di keyboard untuk pindah soal.</p>
        </aside>
    </div>
    @endif
</div>

@if ($total > 0)
<script>
(function () {
    const cards = [...document.querySelectorAll('.q-card')];
    const navs = [...document.querySelectorAll('[data-nav]')];
    let current = {{ (int) $start }};

    function show(i) {
        if (i < 0 || i >= cards.length) return;
        cards[current].classList.remove('current'); navs[current].classList.remove('current');
        current = i;
        cards[i].classList.add('current'); navs[i].classList.add('current');
        document.querySelector('.q-area').scrollTop = 0; window.scrollTo(0, 0);
        cards[i].querySelectorAll('audio').forEach(a => a.pause());
    }
    navs.forEach((a, i) => a.addEventListener('click', e => { e.preventDefault(); show(i); }));
    document.querySelectorAll('[data-go]').forEach(b => b.addEventListener('click', () => show(parseInt(b.dataset.go, 10))));
    document.addEventListener('keydown', e => {
        if (e.target.matches('textarea, input[type=text]')) return;
        if (e.key === 'ArrowRight') show(current + 1);
        if (e.key === 'ArrowLeft') show(current - 1);
    });

    // Tanda sudah dijawab / ragu-ragu di navigasi, seperti di halaman ujian.
    const markNav = card => {
        const nav = navs[parseInt(card.dataset.index, 10)];
        const essay = card.querySelector('[data-essay]');
        nav.classList.toggle('answered', essay ? !!essay.value.trim() : !!card.querySelector('input[type=radio]:checked'));
        nav.classList.toggle('flagged', card.querySelector('[data-flag]').checked);
    };

    // Cek jawaban: tandai kunci (hijau) dan pilihan yang salah (merah), lalu tampilkan pembahasan.
    function reveal(card, open) {
        const box = card.querySelector('[data-keybox]');
        const verdict = card.querySelector('[data-verdict]');
        const picked = card.querySelector('input[type=radio]:checked');
        card.querySelectorAll('.choice').forEach(c => {
            c.classList.toggle('is-key', open && c.dataset.key === '1');
            c.classList.toggle('is-wrong', open && picked && c.contains(picked) && c.dataset.key !== '1');
        });
        box.classList.toggle('open', open);
        const nav = navs[parseInt(card.dataset.index, 10)];
        nav.classList.remove('checked-ok', 'checked-bad');
        verdict.textContent = '';
        if (open && picked) {
            const ok = picked.closest('.choice').dataset.key === '1';
            verdict.textContent = ok ? '✓ Jawaban Anda benar' : '✗ Jawaban Anda salah';
            nav.classList.add(ok ? 'checked-ok' : 'checked-bad');
        }
    }

    cards.forEach(card => {
        card.querySelectorAll('input[type=radio]').forEach(r => r.addEventListener('change', () => {
            markNav(card);
            if (card.querySelector('[data-keybox]').classList.contains('open')) reveal(card, true);
        }));
        card.querySelector('[data-flag]').addEventListener('change', () => markNav(card));
        const essay = card.querySelector('[data-essay]');
        if (essay) essay.addEventListener('input', () => markNav(card));
        card.querySelector('[data-check]').addEventListener('click', () => reveal(card, !card.querySelector('[data-keybox]').classList.contains('open')));
    });

    document.getElementById('show-keys').addEventListener('change', e => cards.forEach(c => reveal(c, e.target.checked)));
    document.getElementById('hide-meta').addEventListener('change', e => document.getElementById('preview').classList.toggle('hide-meta', e.target.checked));

    // Audio: tombol putar seperti peserta; di pratinjau tidak ada batas putar.
    document.querySelectorAll('[data-preview-play]').forEach(btn => btn.addEventListener('click', () => {
        const player = btn.closest('.audio-box').querySelector('audio');
        player.hidden = false; player.play();
    }));
})();
</script>
@endif
</body>
</html>
