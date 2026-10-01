@extends('layouts.app')
@section('title', $user->exists ? 'Edit user' : 'Tambah user')
@section('container_class', 'narrow')
@section('content')
    <div class="page-head">
        <h1>{{ $user->exists ? 'Edit user' : 'Tambah user' }}</h1>
        <a class="btn secondary" href="{{ route('admin.users.index') }}">Kembali</a>
    </div>
    <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}" class="card">
        @csrf
        @if ($user->exists) @method('PUT') @endif

        <div class="field">
            <label for="name">Nama</label>
            <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required>
        </div>
        <div class="row">
            <div class="field">
                <label for="email">Email (untuk login)</label>
                <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required>
            </div>
            <div class="field">
                <label for="phone">No. HP</label>
                <input id="phone" name="phone" type="text" value="{{ old('phone', $user->phone) }}">
            </div>
        </div>
        <div class="field">
            <label for="role">Role</label>
            <select id="role" name="role" required>
                @foreach ($roles as $role)
                    <option value="{{ $role->value }}" @selected(old('role', $user->role?->value) === $role->value)>{{ $role->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="row">
            <div class="field">
                <label for="password">Password {{ $user->exists ? '(kosongkan jika tidak diganti)' : '' }}</label>
                <input id="password" name="password" type="password" autocomplete="new-password" {{ $user->exists ? '' : 'required' }}>
                <div class="hint">Minimal 8 karakter.</div>
            </div>
            <div class="field">
                <label for="password_confirmation">Ulangi password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">
            </div>
        </div>
        @php($profile = $user->profile)
        @php($selectedClasses = collect(old('classroom_ids', $user->exists ? $user->classrooms->pluck('id')->all() : []))->map(fn ($v) => (int) $v))
        <fieldset class="field" id="student-profile" style="border:1px solid var(--line);border-radius:10px;padding:12px 14px">
            <legend style="font-weight:700;font-size:13px;padding:0 6px">Data peserta (khusus role Peserta)</legend>
            <div class="row">
                <div class="field">
                    <label for="nis">NIS</label>
                    <input id="nis" name="nis" type="text" value="{{ old('nis', $profile?->nis) }}">
                </div>
                <div class="field">
                    <label for="level_id">Level saat ini</label>
                    <select id="level_id" name="level_id">
                        <option value="">–</option>
                        @foreach ($levels as $lv)
                            <option value="{{ $lv->id }}" @selected((int) old('level_id', $profile?->level_id) === $lv->id)>{{ $lv->testType->code ?? '' }} {{ $lv->code }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="field">
                    <label for="program_name">Program</label>
                    <input id="program_name" name="program_name" type="text" value="{{ old('program_name', $profile?->program_name) }}" placeholder="mis. Tokutei Ginou – Kaigo">
                </div>
                <div class="field">
                    <label for="joined_at">Tanggal masuk</label>
                    <input id="joined_at" name="joined_at" type="date" value="{{ old('joined_at', $profile?->joined_at?->format('Y-m-d')) }}">
                </div>
            </div>
            @if ($classrooms->isNotEmpty())
                <div class="field">
                    <label>Kelas</label>
                    <div style="display:flex;flex-wrap:wrap;gap:6px 16px">
                        @foreach ($classrooms as $c)
                            <label class="check"><input type="checkbox" name="classroom_ids[]" value="{{ $c->id }}" @checked($selectedClasses->contains($c->id))> {{ $c->name }}</label>
                        @endforeach
                    </div>
                </div>
            @endif
        </fieldset>
        <script>
            (() => {
                const role = document.getElementById('role'), box = document.getElementById('student-profile');
                const sync = () => { box.style.display = role.value === 'student' ? '' : 'none'; };
                role.addEventListener('change', sync); sync();
            })();
        </script>
        <div class="field">
            <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active))> Akun aktif (bisa login)</label>
        </div>
        <button class="btn" type="submit">Simpan</button>
    </form>
@endsection
