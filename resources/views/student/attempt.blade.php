<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $section->examSection->section->name }} · {{ $attempt->exam->title }}</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=4">
    <script>document.documentElement.classList.add('js')</script>
</head>
<body>
@php
    $shuffle = $attempt->exam->shuffle_options;
    $isLast = $attempt->sections->last()->id === $section->id;
    $flat = $groups->flatMap(fn ($g) => $g->map(fn ($item) => [$item, $g->first()]))->values();
@endphp
<div class="exam-page">
    <div class="exam-topbar">
        <div class="brand"><img src="{{ asset('images/logo-160.png') }}" alt="">{{ config('app.brand') }}</div>
        <div class="user-chip">
            <div class="avatar" aria-hidden="true">{{ collect(explode(' ', auth()->user()->name))->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') }}</div>
            <div class="txt"><p>{{ auth()->user()->name }}</p><span>Peserta</span></div>
        </div>
    </div>

    <div class="ujian-head">
        <div>
            <h1>{{ $attempt->exam->title }}</h1>
            <span>Bagian {{ $attempt->sections->search(fn ($s) => $s->id === $section->id) + 1 }} dari {{ $attempt->sections->count() }} · {{ $section->examSection->section->name }} · {{ $items->count() }} soal</span>
            <div class="section-steps">
                @foreach ($attempt->sections as $s)
                    <span @class(['now' => $s->id === $section->id, 'done' => $s->submitted_at])>{{ $s->examSection->section->name }}</span>
                @endforeach
            </div>
        </div>
        <div class="timer-chip" id="timer" role="timer" aria-live="off">⏱ --:--</div>
    </div>

    <form method="POST" action="{{ route('student.attempts.submit', $attempt) }}" id="exam-form" class="ujian-shell">
        @csrf
        <input type="hidden" name="section_id" value="{{ $section->id }}">
        <input type="hidden" name="timeout" value="0" id="timeout-flag">

        <div class="q-area">
            @include('partials.flash')

            @foreach ($flat as $n => [$item, $first])
                @php($q = $item->question)
                @php($passage = $q->passage)
                <section class="q-card @if ($n === 0) current @endif" id="q{{ $item->id }}" data-item="{{ $item->id }}" data-index="{{ $n }}" aria-label="Soal {{ $n + 1 }}">
                    <div class="q-head">
                        <span class="q-no">Soal No. {{ $n + 1 }} dari {{ $items->count() }}@if ($q->mondai) · {{ $q->mondai }}@endif</span>
                        <span>
                            <span class="save-state" data-state></span>
                            <label class="flag-toggle"><input type="checkbox" data-flag @checked($item->is_flagged)> ragu-ragu</label>
                        </span>
                    </div>

                    @if ($passage)
                        <div class="passage" lang="ja">
                            @if ($passage->title)<div class="muted" style="font-size:.85rem">{{ $passage->title }}</div>@endif
                            @if ($passage->image_path)<img class="q-img" src="{{ route('media', $passage->image_path) }}" alt="">@endif
                            @if ($passage->content)@jp($passage->content)@endif
                            @if ($passage->audio_path)@include('student._audio', ['item' => $first])@endif
                        </div>
                    @endif

                    @if ($q->image_path)<img class="q-img" src="{{ route('media', $q->image_path) }}" alt="">@endif
                    @if ($q->audio_path)@include('student._audio', ['item' => $item])@endif
                    @if ($q->stem)<p class="q-stem" lang="ja">@jp($q->stem)</p>@endif

                    @if ($item->correct_option_id === null)
                        <textarea name="answers[{{ $item->id }}]" data-essay rows="4" lang="ja" maxlength="2000" placeholder="Tulis jawaban di sini" aria-label="Jawaban soal {{ $n + 1 }}">{{ $item->answer_text }}</textarea>
                    @else
                        @php($opts = $shuffle ? $q->options->sortBy(fn ($o) => crc32($attempt->id.'-'.$o->id))->values() : $q->options->values())
                        <div class="choices" role="radiogroup" aria-label="Pilihan soal {{ $n + 1 }}">
                            @foreach ($opts as $pos => $opt)
                                <label class="choice">
                                    <input type="radio" name="answers[{{ $item->id }}]" value="{{ $opt->id }}" @checked($item->selected_option_id === $opt->id)>
                                    <span class="lbl">{{ ['A', 'B', 'C', 'D'][$pos] ?? $pos + 1 }}</span>
                                    <span lang="ja">
                                        @if ($opt->image_path)<img src="{{ route('media', $opt->image_path) }}" alt="" style="max-height:90px;display:block">@endif
                                        @jp($opt->content)
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif

                    <div class="q-nav-btns js-only">
                        <button type="button" class="nbtn prev" data-go="{{ $n - 1 }}" @disabled($n === 0)>‹ Sebelumnya</button>
                        @if ($n < $items->count() - 1)
                            <button type="button" class="nbtn next" data-go="{{ $n + 1 }}">Selanjutnya ›</button>
                        @endif
                    </div>
                </section>
            @endforeach
        </div>

        <aside class="q-side">
            <h2>Navigasi Soal</h2>
            <div class="q-grid" id="nav-grid">
                @foreach ($flat as $n => [$item])
                    <a href="#q{{ $item->id }}" data-nav="{{ $item->id }}" data-index="{{ $n }}"
                       @class(['current' => $n === 0, 'answered' => $item->selected_option_id || $item->answer_text, 'flagged' => $item->is_flagged])
                       aria-label="Soal {{ $n + 1 }}">{{ $n + 1 }}</a>
                @endforeach
            </div>
            <div class="legend">
                <span><i class="sw cur"></i>Sedang dikerjakan</span>
                <span><i class="sw ans"></i>Sudah dijawab</span>
                <span><i class="sw"></i>Belum dijawab</span>
                <span><i class="sw flag"></i>Ragu-ragu</span>
            </div>
            <button type="submit" class="btn-finish" id="submit-btn">{{ $isLast ? 'Selesaikan Ujian' : 'Kumpulkan Bagian Ini' }}</button>
            <p class="muted" style="font-size:11.5px;margin-top:10px">Jawaban tersimpan otomatis. Setelah bagian dikumpulkan, Anda tidak bisa kembali ke bagian ini.</p>
        </aside>
    </form>
</div>

<script>
(function () {
    const form = document.getElementById('exam-form');
    const token = document.querySelector('meta[name=csrf-token]').content;
    const answerUrl = @json(route('student.attempts.answer', $attempt));
    const positionUrl = @json(route('student.attempts.position', $attempt));
    const cards = [...document.querySelectorAll('.q-card')];
    const navs = [...document.querySelectorAll('[data-nav]')];
    let remaining = {{ (int) $remaining }};
    let submitting = false;
    let current = 0;

    // ---- Satu soal per layar ----
    function show(i) {
        if (i < 0 || i >= cards.length) return;
        cards[current].classList.remove('current'); navs[current].classList.remove('current');
        current = i;
        cards[i].classList.add('current'); navs[i].classList.add('current');
        document.querySelector('.q-area').scrollTop = 0; window.scrollTo(0, 0);
        report();
    }

    // ---- Posisi soal untuk pengawas (nomor yang sedang dibuka + tanda online) ----
    function report() {
        fetch(positionUrl, {
            method: 'POST', keepalive: true,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
            body: JSON.stringify({ no: current + 1 }),
        }).then(res => {
            // 409 = ujian sudah selesai/dihentikan pengawas: muat ulang ke halaman hasil.
            if (res.status === 409 && !submitting) { submitting = true; location.reload(); return null; }
            return res.ok ? res.json() : null;
        }).then(data => {
            // Pengawas bisa menambah waktu: ikuti sisa waktu dari server.
            if (data && typeof data.remaining === 'number' && data.remaining > remaining + 2) remaining = data.remaining;
        }).catch(() => {});
    }

    // ---- Keluar dari halaman ujian (pindah tab/jendela/aplikasi): dicatat untuk pengawas ----
    // Satu kali keluar dihitung sekali; jeda 1,5 detik supaya dialog konfirmasi/pemutar audio tidak ikut terhitung.
    const awayUrl = @json(route('student.attempts.away', $attempt));
    let awayTimer = null, away = false;
    const markAway = () => {
        if (away || submitting) return;
        away = true;
        fetch(awayUrl, { method: 'POST', keepalive: true, headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } }).catch(() => {});
    };
    const leave = () => { if (!awayTimer && !away) awayTimer = setTimeout(markAway, 1500); };
    const back = () => { clearTimeout(awayTimer); awayTimer = null; if (away) { away = false; report(); } };
    document.addEventListener('visibilitychange', () => { if (document.hidden) { clearTimeout(awayTimer); awayTimer = null; markAway(); } else back(); });
    window.addEventListener('blur', leave);
    window.addEventListener('focus', back);
    report(); setInterval(report, 15000);
    navs.forEach((a, i) => a.addEventListener('click', e => { e.preventDefault(); show(i); }));
    document.querySelectorAll('[data-go]').forEach(b => b.addEventListener('click', () => show(parseInt(b.dataset.go, 10))));
    document.addEventListener('keydown', e => {
        if (e.target.matches('textarea, input[type=text]')) return;
        if (e.key === 'ArrowRight') show(current + 1);
        if (e.key === 'ArrowLeft') show(current - 1);
    });

    // ---- Timer (tampilan; batas waktu sebenarnya dicek server) ----
    const timer = document.getElementById('timer');
    const fmt = s => { const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), r = s % 60; return (h ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(r).padStart(2, '0'); };
    const tick = () => {
        timer.textContent = '⏱ ' + fmt(Math.max(0, remaining));
        timer.classList.toggle('low', remaining <= 60);
        if (remaining <= 0 && !submitting) { document.getElementById('timeout-flag').value = '1'; submitting = true; form.submit(); return; }
        remaining--;
    };
    tick(); setInterval(tick, 1000);

    form.addEventListener('submit', e => {
        if (submitting) return;
        const empty = navs.filter(a => !a.classList.contains('answered')).length;
        const msg = empty ? `Masih ada ${empty} soal belum dijawab. Kumpulkan sekarang?` : 'Kumpulkan bagian ini? Anda tidak bisa kembali.';
        if (!confirm(msg)) { e.preventDefault(); return; }
        submitting = true;
    });

    // ---- Autosave ----
    async function save(card, payload) {
        const state = card.querySelector('[data-state]');
        state.textContent = 'menyimpan…';
        try {
            const res = await fetch(answerUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                body: JSON.stringify(Object.assign({ item_id: card.dataset.item, flagged: card.querySelector('[data-flag]').checked }, payload)),
            });
            const data = await res.json();
            if (data.ok) { state.textContent = '✓ tersimpan'; if (typeof data.remaining === 'number') remaining = data.remaining; }
            else if (data.reason === 'timeout' || data.reason === 'section_closed') { state.textContent = data.message; location.reload(); }
            else { state.textContent = data.message || 'gagal menyimpan'; }
        } catch (e) { state.textContent = 'offline — akan dikirim saat dikumpulkan'; }
    }
    const payloadOf = card => {
        const essay = card.querySelector('[data-essay]');
        if (essay) return { text: essay.value };
        const checked = card.querySelector('input[type=radio]:checked');
        return { option_id: checked ? parseInt(checked.value, 10) : null };
    };
    const markNav = card => {
        const p = payloadOf(card), nav = navs[parseInt(card.dataset.index, 10)];
        nav.classList.toggle('answered', !!(p.option_id || (p.text && p.text.trim())));
        nav.classList.toggle('flagged', card.querySelector('[data-flag]').checked);
    };
    cards.forEach(card => {
        card.querySelectorAll('input[type=radio]').forEach(r => r.addEventListener('change', () => { markNav(card); save(card, payloadOf(card)); }));
        card.querySelector('[data-flag]').addEventListener('change', () => { markNav(card); save(card, payloadOf(card)); });
        const essay = card.querySelector('[data-essay]');
        if (essay) { let t; essay.addEventListener('input', () => { markNav(card); clearTimeout(t); t = setTimeout(() => save(card, payloadOf(card)), 800); }); }
    });

    // ---- Audio dengan batas putar ----
    document.querySelectorAll('[data-play]').forEach(btn => btn.addEventListener('click', async () => {
        btn.disabled = true;
        const box = btn.closest('.audio-box'), info = box.querySelector('[data-plays]'), player = box.querySelector('audio');
        try {
            const res = await fetch(btn.dataset.play, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } });
            const data = await res.json();
            if (!data.ok) { info.textContent = data.message; return; }
            player.src = data.url; player.hidden = false; player.play();
            if (data.plays_left !== null) info.textContent = `Sisa putar: ${data.plays_left}×`;
            player.onended = () => { if (data.plays_left === null || data.plays_left > 0) btn.disabled = false; };
        } catch (e) { info.textContent = 'Gagal memuat audio.'; btn.disabled = false; }
    }));
})();
</script>
</body>
</html>
