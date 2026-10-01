@extends('layouts.app')
@section('title', 'Kelas '.$class->name)
@section('content')
    <div class="page-head">
        <div><h1>Kelas {{ $class->name }}</h1><p>{{ $class->level?->code ?? 'Semua level' }} · Instruktur: {{ $class->sensei?->name ?? '–' }} · {{ $class->students->count() }} peserta</p></div>
        <div class="btnrow"><a class="btn secondary" href="{{ route('manage.classes.edit', $class) }}">Edit kelas</a><a class="btn secondary" href="{{ route('manage.classes.index') }}">Semua kelas</a></div>
    </div>
    <div class="grid" style="grid-template-columns:minmax(0,1.5fr) minmax(280px,1fr);align-items:start">
        <div>
            <div class="card">
                <div class="card-head"><h2>Jadwal</h2></div>
                <div class="table-wrap"><table>
                    <tr><th>Tanggal</th><th>Jam</th><th>Topik</th><th>Instruktur</th><th>Hadir</th><th></th></tr>
                    @forelse ($sessions as $s)
                        <tr @if ($s->date->isToday()) style="background:var(--info-bg)" @endif>
                            <td>{{ $s->date->translatedFormat('D, d M Y') }}</td>
                            <td>{{ $s->timeRange() }}</td>
                            <td>{{ $s->topic }}</td>
                            <td>{{ $s->sensei?->name ?? '–' }}</td>
                            <td>{{ $s->present_count }}/{{ $class->students->count() }}</td>
                            <td class="actions">
                                <a class="btn small" href="{{ route('manage.sessions.attendance', $s) }}">Absensi</a>
                                <form method="POST" action="{{ route('manage.sessions.destroy', $s) }}" style="display:inline" onsubmit="return confirm('Hapus jadwal ini?')">@csrf @method('DELETE')<button class="btn small danger" aria-label="Hapus jadwal">✕</button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="muted">Belum ada jadwal.</td></tr>
                    @endforelse
                </table></div>
            </div>
            <form method="POST" action="{{ route('manage.classes.sessions.store', $class) }}" class="card">
                @csrf
                <div class="card-head"><h2>Tambah jadwal</h2></div>
                <div class="row">
                    <div class="field"><label for="date">Tanggal</label><input id="date" name="date" type="date" value="{{ old('date', today()->format('Y-m-d')) }}" required></div>
                    <div class="field"><label for="starts_at">Mulai</label><input id="starts_at" name="starts_at" type="time" value="{{ old('starts_at', '09:00') }}" required></div>
                    <div class="field"><label for="ends_at">Selesai</label><input id="ends_at" name="ends_at" type="time" value="{{ old('ends_at', '10:30') }}" required></div>
                </div>
                <div class="row">
                    <div class="field"><label for="topic">Topik</label><input id="topic" name="topic" type="text" maxlength="150" value="{{ old('topic') }}" required></div>
                    <div class="field"><label for="sensei_id">Instruktur</label>
                        <select id="sensei_id" name="sensei_id"><option value="">(instruktur kelas)</option>
                            @foreach ($senseis as $s)<option value="{{ $s->id }}" @selected((int) old('sensei_id') === $s->id)>{{ $s->name }}</option>@endforeach
                        </select></div>
                    <div class="field"><label for="repeat_until">Ulangi tiap minggu sampai</label><input id="repeat_until" name="repeat_until" type="date" value="{{ old('repeat_until') }}"><div class="hint">Kosongkan untuk satu kali.</div></div>
                </div>
                <button class="btn">Tambah jadwal</button>
            </form>
        </div>
        <div>
            <div class="card">
                <div class="card-head"><h2>Peserta ({{ $class->students->count() }})</h2></div>
                @forelse ($class->students as $st)
                    <div class="doc-row">
                        <span>{{ $st->name }}@if ($st->profile?->nis)<br><small class="muted">NIS {{ $st->profile->nis }}</small>@endif</span>
                        <form method="POST" action="{{ route('manage.classes.students.remove', [$class, $st]) }}" onsubmit="return confirm('Keluarkan {{ $st->name }} dari kelas?')">@csrf @method('DELETE')<button class="btn small secondary">Keluarkan</button></form>
                    </div>
                @empty
                    <p class="muted">Belum ada peserta.</p>
                @endforelse
            </div>
            @if ($available->isNotEmpty())
                <form method="POST" action="{{ route('manage.classes.students.add', $class) }}" class="card">
                    @csrf
                    <div class="card-head"><h2>Tambah peserta</h2></div>
                    <div class="field">
                        <select name="student_ids[]" multiple size="8" aria-label="Pilih peserta">
                            @foreach ($available as $st)<option value="{{ $st->id }}">{{ $st->name }} ({{ $st->email }})</option>@endforeach
                        </select>
                        <div class="hint">Tahan Ctrl/Cmd untuk memilih beberapa.</div>
                    </div>
                    <button class="btn">Tambahkan</button>
                </form>
            @endif
        </div>
    </div>
@endsection
