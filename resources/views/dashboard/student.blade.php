@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
    @php($stageKeys = array_keys(\App\Support\ProgramJepang::STAGES))
    <div class="welcome-banner">
        <div>
            <h2>Selamat datang, {{ auth()->user()->name }}! 🌸</h2>
            <p>Terus belajar dan raih impianmu bekerja di Jepang.</p>
            @if ($learningProgress !== null)
                <div class="progress-track"><div class="progress-fill" style="width: {{ $learningProgress }}%"></div></div>
                <p style="margin:6px 0 0;font-size:12px">Progress belajar {{ $learningProgress }}% ({{ $materialsDone }}/{{ $materialsTotal }} materi)</p>
            @endif
        </div>
        <svg class="fuji-mini" viewBox="0 0 170 110" aria-hidden="true">
            <circle cx="128" cy="30" r="16" fill="#e8879b" opacity=".85"/>
            <polygon points="0,110 55,45 70,52 85,28 100,52 115,45 170,110" fill="#fff" opacity=".85"/>
            <polygon points="72,48 85,28 98,48 90,44 85,50 80,44" fill="#fff"/>
        </svg>
    </div>

    <div class="grid cols-4" style="margin-bottom:16px">
        <a class="stat" href="{{ route('profile.edit') }}"><div class="ic">📶</div><div><p class="label">Level saat ini</p><p class="value">{{ $level?->code ?? '–' }}</p></div></a>
        <a class="stat" href="{{ route('student.classes') }}"><div class="ic green">✅</div><div><p class="label">Kehadiran</p><p class="value">{{ $attendanceRate !== null ? $attendanceRate.'%' : '–' }}</p></div></a>
        <a class="stat" href="{{ route('student.materials') }}"><div class="ic orange">📘</div><div><p class="label">Materi selesai</p><p class="value">{{ $materialsDone }}</p></div></a>
        <a class="stat" href="{{ route('student.certificates') }}"><div class="ic purple">🏅</div><div><p class="label">Sertifikat</p><p class="value">{{ $certificateCount }}</p></div></a>
    </div>

    @if ($hasProgram)
        <div class="card">
            <div class="card-head"><h2>Tahapan Program Jepang</h2><a href="{{ route('student.program') }}">Detail</a></div>
            @include('program._timeline', ['stages' => $stages])
        </div>
    @endif

    <div class="grid cols-2" style="align-items:start">
        <div class="card">
            <div class="card-head"><h2>Materi terbaru</h2><a href="{{ route('student.materials') }}">Lihat semua</a></div>
            @forelse ($latestMaterials as $m)
                <div class="list-row">
                    <div class="ic">{{ $m->icon() }}</div>
                    <div class="info"><p>{{ $m->title }}</p><span>{{ $m->chapter }}@if ($m->category) · {{ $m->category }}@endif</span></div>
                    <a class="btn small secondary" href="{{ route('student.materials.show', $m) }}">Mulai</a>
                </div>
            @empty
                <p class="muted">Belum ada materi untuk level Anda.</p>
            @endforelse
        </div>
        <div class="card">
            <div class="card-head"><h2>Jadwal kelas hari ini</h2><a href="{{ route('student.classes') }}">Lihat semua</a></div>
            @forelse ($todaySessions as $s)
                <div class="list-row">
                    <div class="ic orange">🕘</div>
                    <div class="info"><p>{{ $s->timeRange() }} · {{ $s->topic }}</p><span>{{ $s->classroom->name }}@if ($s->sensei) · Instruktur: {{ $s->sensei->name }}@endif</span></div>
                </div>
            @empty
                <p class="muted">Tidak ada kelas hari ini.</p>
            @endforelse
        </div>
    </div>

    <div class="grid cols-2" style="align-items:start">
        <div class="card">
            <div class="card-head"><h2>Mulai ujian</h2><a href="{{ route('student.exams') }}">Menu ujian</a></div>
            <form method="POST" action="{{ route('student.join') }}" class="inline-form">
                @csrf
                <div class="field" style="flex:1">
                    <input id="code" name="code" type="text" value="{{ old('code') }}" maxlength="20" required placeholder="KODE UJIAN" aria-label="Kode ujian"
                           style="text-transform:uppercase;letter-spacing:.15em;font-size:1.05rem;font-weight:700" autocomplete="off">
                </div>
                <button class="btn big" type="submit">Mulai</button>
            </form>
            @foreach ($ongoing as $attempt)
                <div class="list-row"><div class="ic">✏️</div><div class="info"><p>{{ $attempt->exam->title }}</p><span>Sedang dikerjakan</span></div>
                    <a class="btn small" href="{{ route('student.attempts.show', $attempt) }}">Lanjutkan</a></div>
            @endforeach
            @foreach ($pendingPayments as $p)
                <div class="list-row"><div class="ic orange">💳</div><div class="info"><p>{{ $p->exam->title }}</p><span>Menunggu pembayaran · Rp{{ number_format($p->amount, 0, ',', '.') }}</span></div>
                    <a class="btn small gold" href="{{ route('student.payments.show', $p->exam->code) }}">Bayar</a></div>
            @endforeach
        </div>
        <div class="card">
            <div class="card-head"><h2>Riwayat ujian</h2><a href="{{ route('student.results') }}">Lihat semua</a></div>
            @forelse ($history as $attempt)
                @php($passed = $attempt->exam->passed($attempt->score !== null ? (float) $attempt->score : null))
                <a class="list-row" href="{{ route('student.attempts.result', $attempt) }}" style="text-decoration:none;color:inherit">
                    <div @class(['ic', 'green' => $passed !== false && ! $attempt->isPendingReview(), 'orange' => $passed === false || $attempt->isPendingReview()])>{{ $attempt->isPendingReview() ? '⏳' : ($passed === false ? '△' : '✓') }}</div>
                    <div class="info"><p>{{ $attempt->exam->title }}</p><span>{{ $attempt->finished_at?->translatedFormat('d M Y') }} · {{ $attempt->isPendingReview() ? 'menunggu penilaian esai' : 'Nilai '.number_format((float) $attempt->score, 0) }}</span></div>
                </a>
            @empty
                <p class="muted">Belum ada ujian yang selesai.</p>
            @endforelse
        </div>
    </div>
@endsection
