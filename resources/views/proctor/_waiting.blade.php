{{-- Peserta yang sudah login hari ini tetapi belum memasukkan kode ujian. --}}
@if ($waiting->isNotEmpty())
    <div class="card">
        <div class="card-head"><h2>Sudah login, belum mulai ujian ({{ $waiting->count() }})</h2></div>
        <div class="pr-chips">
            @foreach ($waiting as $u)
                <span class="pr-chip" title="{{ $u->email }}">{{ $u->name }} <span class="muted">· {{ $u->role->label() }} · {{ $u->last_login_at->format('H:i') }}</span></span>
            @endforeach
        </div>
    </div>
@endif
