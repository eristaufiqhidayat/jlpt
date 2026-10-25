{{-- Peserta tes umum yang login hari ini dan siswa yang membuka halaman Ujian, tetapi belum memasukkan kode. --}}
@if ($waiting->isNotEmpty())
    <div class="card">
        <div class="card-head"><h2>Siap ujian, belum memasukkan kode ({{ $waiting->count() }})</h2></div>
        <div class="pr-chips">
            @foreach ($waiting as $u)
                <span class="pr-chip" title="{{ $u->email }}">{{ $u->name }} <span class="muted">· {{ $u->role->label() }} · {{ ($u->exam_page_seen_at?->isToday() ? $u->exam_page_seen_at : $u->last_login_at)?->format('H:i') }}</span></span>
            @endforeach
        </div>
    </div>
@endif
