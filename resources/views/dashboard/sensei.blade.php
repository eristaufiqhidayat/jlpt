@extends('layouts.app')
@section('title', 'Beranda Sensei')
@section('content')
    <div class="page-head">
        <div><h1>おかえりなさい、{{ auth()->user()->name }}</h1><p>Kelola Pelatihan Soal dan ujian peserta.</p></div>
        <div>
            <a class="btn" href="{{ route('sensei.questions.create') }}">+ Soal baru</a>
            <a class="btn secondary" href="{{ route('sensei.exams.create') }}">+ Ujian baru</a>
        </div>
    </div>
    <div class="grid cols-4">
        <a class="stat" href="{{ route('sensei.questions.index', ['status' => 'published']) }}"><div class="ic">🗂️</div><div><p class="label">Soal siap pakai</p><p class="value">{{ $questionCount }}</p></div></a>
        <a class="stat" href="{{ route('sensei.questions.index', ['status' => 'draft']) }}"><div class="ic orange">📝</div><div><p class="label">Soal draft</p><p class="value">{{ $draftCount }}</p></div></a>
        <a class="stat" href="{{ route('sensei.grading.index') }}"><div class="ic purple">✍️</div><div><p class="label">Esai belum dinilai</p><p class="value">{{ $pendingEssays }}</p></div></a>
        <a class="stat" href="{{ route('sensei.legacy.index', ['status' => 'problem']) }}"><div class="ic pink">📦</div><div><p class="label">Arsip lama perlu dicek</p><p class="value">{{ $legacyIssues }}</p></div></a>
    </div>
    @if (! empty($todaySessions) && $todaySessions->isNotEmpty())
        <div class="card" style="margin-top:16px">
            <div class="card-head"><h2>Jadwal kelas hari ini</h2><a href="{{ route('manage.classes.index') }}">Semua kelas</a></div>
            @foreach ($todaySessions as $s)
                <div class="list-row">
                    <div class="ic orange">🕘</div>
                    <div class="info"><p>{{ $s->timeRange() }} · {{ $s->topic }}</p><span>{{ $s->classroom->name }}@if ($s->sensei) · {{ $s->sensei->name }}@endif</span></div>
                    <a class="btn small" href="{{ route('manage.sessions.attendance', $s) }}">Absensi</a>
                </div>
            @endforeach
        </div>
    @endif
    <div class="card" style="margin-top:16px">
        <h2>Ujian terbaru saya</h2>
        @if ($myExams->isEmpty())
            <p class="muted">Belum ada ujian. <a href="{{ route('sensei.exams.create') }}">Buat ujian pertama</a>.</p>
        @else
            <div class="table-wrap"><table>
                <tr><th>Judul</th><th>Level</th><th>Kode</th><th>Status</th><th class="num">Peserta</th><th></th></tr>
                @foreach ($myExams as $exam)
                    <tr>
                        <td>{{ $exam->title }}</td>
                        <td>{{ $exam->level->testType->code }} {{ $exam->level->code }}</td>
                        <td><span class="code">{{ $exam->code }}</span></td>
                        <td>@include('sensei.exams._status', ['exam' => $exam])</td>
                        <td class="num">{{ $exam->attempts_count }}</td>
                        <td class="actions"><a href="{{ route('sensei.exams.show', $exam) }}">Detail</a></td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    </div>
@endsection
