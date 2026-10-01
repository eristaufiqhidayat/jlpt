@extends('layouts.app')
@section('title', 'Absensi')
@section('content')
    <div class="page-head">
        <div><h1>Absensi {{ $session->classroom->name }}</h1><p>{{ $session->date->translatedFormat('l, d F Y') }} · {{ $session->timeRange() }} · {{ $session->topic }}</p></div>
        <a class="btn secondary" href="{{ route('manage.classes.show', $session->classroom) }}">Kembali</a>
    </div>
    <form method="POST" action="{{ route('manage.sessions.attendance.save', $session) }}" class="card">
        @csrf
        @if ($session->classroom->students->isEmpty())
            <p class="muted">Kelas ini belum punya peserta.</p>
        @else
            <p class="btnrow js-only" style="margin-top:0"><button type="button" class="btn small secondary" onclick="document.querySelectorAll('input[value=hadir]').forEach(r => r.checked = true)">Tandai semua hadir</button></p>
            <div class="table-wrap"><table>
                <tr><th>Peserta</th>@foreach (\App\Models\Attendance::STATUSES as $label)<th>{{ $label }}</th>@endforeach</tr>
                @foreach ($session->classroom->students as $st)
                    @php($cur = $records->get($st->id)?->status)
                    <tr>
                        <td>{{ $st->name }}</td>
                        @foreach (\App\Models\Attendance::STATUSES as $v => $label)
                            <td><input type="radio" name="status[{{ $st->id }}]" value="{{ $v }}" @checked($cur === $v) aria-label="{{ $st->name }} {{ $label }}"></td>
                        @endforeach
                    </tr>
                @endforeach
            </table></div>
            <button class="btn" style="margin-top:12px">Simpan absensi</button>
        @endif
    </form>
@endsection
