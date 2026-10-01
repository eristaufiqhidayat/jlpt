@extends('layouts.app')
@section('title', 'Profil')
@section('content')
    @php($p = $user->profile)
    <div class="page-head"><div><h1>Profil Saya</h1></div></div>
    <div class="grid" style="grid-template-columns:minmax(260px,1fr) minmax(0,1.6fr);align-items:start">
        <div class="card" style="text-align:center">
            <div class="avatar lg" style="margin:6px auto 10px">{{ $user->initials() }}</div>
            <h2 style="margin:0 0 4px">{{ $user->name }}</h2>
            <p class="muted" style="margin:0 0 12px">{{ $user->role->label() }}</p>
            @if ($user->isStudent())
                <div style="text-align:left">
                    <div class="doc-row"><span>NIS</span><strong>{{ $p?->nis ?? '–' }}</strong></div>
                    <div class="doc-row"><span>Level</span><strong>{{ $p?->level?->code ?? '–' }}</strong></div>
                    <div class="doc-row"><span>Program</span><strong>{{ $p?->program_name ?? '–' }}</strong></div>
                    <div class="doc-row"><span>Kelas</span><strong>{{ $user->classrooms->pluck('name')->implode(', ') ?: '–' }}</strong></div>
                    <div class="doc-row"><span>Tanggal masuk</span><strong>{{ $p?->joined_at?->translatedFormat('d M Y') ?? '–' }}</strong></div>
                    @if ($progress !== null)
                        <div style="margin-top:10px;font-size:12.5px;font-weight:700;display:flex;justify-content:space-between"><span>Progress Program Jepang</span><span>{{ $progress }}%</span></div>
                        <div class="bar-track" style="margin-top:6px"><span style="width: {{ $progress }}%"></span></div>
                    @endif
                </div>
            @endif
        </div>
        <form method="POST" action="{{ route('profile.update') }}" class="card">
            @csrf @method('PUT')
            <div class="card-head"><h2>Data akun</h2></div>
            <div class="row">
                <div class="field"><label for="name">Nama</label><input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required></div>
                <div class="field"><label for="phone">No. HP</label><input id="phone" name="phone" type="text" value="{{ old('phone', $user->phone) }}"></div>
            </div>
            <div class="field"><label>Email</label><input type="email" value="{{ $user->email }}" disabled><div class="hint">Email login hanya bisa diubah admin.</div></div>
            <div class="card-head" style="margin-top:10px"><h2>Ganti password</h2></div>
            <div class="field"><label for="current_password">Password lama</label><input id="current_password" name="current_password" type="password" autocomplete="current-password">
                @error('current_password')<div class="error-text">{{ $message }}</div>@enderror</div>
            <div class="row">
                <div class="field"><label for="password">Password baru</label><input id="password" name="password" type="password" autocomplete="new-password">
                    @error('password')<div class="error-text">{{ $message }}</div>@enderror</div>
                <div class="field"><label for="password_confirmation">Ulangi password baru</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"></div>
            </div>
            <button class="btn">Simpan</button>
        </form>
    </div>
@endsection
