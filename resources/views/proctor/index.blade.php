@extends('layouts.app')
@section('title', 'Pengawasan Ujian')
@push('head')
<style>
    .pr-live { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--ink-soft); }
    .pr-live::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: var(--green); animation: pr-pulse 1.6s infinite; }
    .pr-live.paused::before { background: #bbb; animation: none; }
    @keyframes pr-pulse { 50% { opacity: .3; } }
    .pr-name { display: flex; align-items: center; gap: 7px; }
    .pr-dot { width: 9px; height: 9px; border-radius: 50%; background: #c9c5ba; flex-shrink: 0; }
    .pr-dot.on { background: var(--green); box-shadow: 0 0 0 3px var(--ok-bg); }
    .pr-sub { font-size: 11.5px; margin-top: 3px; }
    .pr-num { white-space: nowrap; font-variant-numeric: tabular-nums; }
    .pr-big { font-size: 18px; }
    .pr-timer { font-size: 16px; font-weight: 800; font-variant-numeric: tabular-nums; }
    .pr-timer.low { color: var(--sakura-deep); }
    .pr-timer.over { color: var(--ink-soft); font-size: 12px; font-weight: 700; }
    .pr-bar { height: 4px; background: #eeeae0; border-radius: 4px; margin-top: 5px; width: 70px; overflow: hidden; }
    .pr-bar span { display: block; height: 100%; background: var(--blue); }
    tr.pr-done td { opacity: .7; }
    .pr-chips { display: flex; flex-wrap: wrap; gap: 6px; }
    .pr-chip { background: #f6f4ee; border: 1px solid var(--line); border-radius: 20px; padding: 4px 10px; font-size: 12px; }
</style>
@endpush
@section('content')
    <div class="page-head">
        <div>
            <h1>Pengawasan Ujian</h1>
            <p>Peserta yang masuk, sesi dan nomor soal yang sedang dikerjakan, serta sisa waktu masing-masing.</p>
        </div>
        <span class="pr-live" id="pr-live">Diperbarui otomatis</span>
    </div>

    <form method="GET" class="filters" id="pr-filters">
        <div class="field">
            <label for="test_type_id">Klasifikasi ujian</label>
            <select id="test_type_id" name="test_type_id" onchange="this.form.exam_id.value='';this.form.submit()">
                <option value="">Semua klasifikasi</option>
                @foreach ($testTypes as $t)
                    <option value="{{ $t->id }}" @selected($filters['test_type_id'] === $t->id)>{{ $t->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="min-width:220px">
            <label for="exam_id">Ujian</label>
            <select id="exam_id" name="exam_id" onchange="this.form.submit()">
                <option value="">Semua ujian</option>
                @foreach ($exams as $e)
                    <option value="{{ $e->id }}" @selected($filters['exam_id'] === $e->id)>{{ $e->title }} ({{ $e->code }} · {{ $e->level->code }})</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status" onchange="this.form.submit()">
                <option value="all" @selected($filters['status'] === 'all')>Semua</option>
                <option value="ongoing" @selected($filters['status'] === 'ongoing')>Sedang mengerjakan</option>
                <option value="finished" @selected($filters['status'] === 'finished')>Sudah selesai</option>
            </select>
        </div>
        <div class="field">
            <label for="date">Tanggal</label>
            <input id="date" type="date" name="date" value="{{ $filters['date']->format('Y-m-d') }}" max="{{ today()->format('Y-m-d') }}" onchange="this.form.submit()">
        </div>
        <noscript><button class="btn" type="submit">Tampilkan</button></noscript>
    </form>

    <div class="grid cols-4" style="margin-bottom:16px">
        <div class="stat"><div class="ic">🚪</div><div><p class="label">Sudah masuk ujian</p><p class="value" data-stat="joined">{{ $stats['joined'] }}</p></div></div>
        <div class="stat"><div class="ic orange">✏️</div><div><p class="label">Sedang mengerjakan</p><p class="value" data-stat="ongoing">{{ $stats['ongoing'] }}</p></div></div>
        <div class="stat"><div class="ic green">📶</div><div><p class="label">Online sekarang</p><p class="value" data-stat="online">{{ $stats['online'] }}</p></div></div>
        <div class="stat"><div class="ic purple">✓</div><div><p class="label">Sudah selesai</p><p class="value" data-stat="finished">{{ $stats['finished'] }}</p></div></div>
    </div>

    <div class="card flush">
        <div class="table-wrap"><table>
            <thead>
                <tr><th>Peserta</th><th>Klasifikasi · Ujian</th><th>Sesi</th><th>No. soal</th><th>Terjawab</th><th>Sisa waktu</th><th>Status</th></tr>
            </thead>
            <tbody id="pr-rows">@include('proctor._rows')</tbody>
        </table></div>
    </div>
    <p class="muted" style="font-size:12px;margin-top:-6px">● hijau = halaman ujian peserta aktif dalam 90 detik terakhir. No. soal = nomor yang sedang dibuka di sesi berjalan.</p>

    <div id="pr-waiting">@include('proctor._waiting')</div>
@endsection

@push('scripts')
<script>
(function () {
    const dataUrl = @json(route('proctor.data')) + location.search;
    const rows = document.getElementById('pr-rows'), waiting = document.getElementById('pr-waiting'), live = document.getElementById('pr-live');
    let offset = {{ now()->getTimestampMs() }} - Date.now(); // selisih jam server dan jam browser

    const fmt = s => { const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), r = s % 60; return (h ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(r).padStart(2, '0'); };
    function tick() {
        const now = Date.now() + offset;
        document.querySelectorAll('[data-deadline]').forEach(el => {
            const s = Math.round((parseInt(el.dataset.deadline, 10) - now) / 1000);
            el.textContent = s > 0 ? fmt(s) : 'Waktu habis';
            el.classList.toggle('low', s > 0 && s <= 300);
            el.classList.toggle('over', s <= 0);
        });
    }
    tick(); setInterval(tick, 1000);

    async function refresh() {
        if (document.hidden) return;
        try {
            const res = await fetch(dataUrl, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) throw new Error(res.status);
            const data = await res.json();
            offset = data.now - Date.now();
            rows.innerHTML = data.rows;
            waiting.innerHTML = data.waiting;
            Object.entries(data.stats).forEach(([k, v]) => { const el = document.querySelector(`[data-stat="${k}"]`); if (el) el.textContent = v; });
            live.classList.remove('paused'); live.textContent = 'Diperbarui ' + new Date().toLocaleTimeString('id-ID');
            tick();
        } catch (e) {
            live.classList.add('paused'); live.textContent = 'Gagal memperbarui, mencoba lagi…';
        }
    }
    setInterval(refresh, 10000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();
</script>
@endpush
