@extends('layouts.app')
@section('title', 'Hasil Ujian')
@section('content')
    @php
        $exam = $attempt->exam;
        $score = $attempt->score !== null ? (float) $attempt->score : null;
        $passed = $exam->passed($score);
        $show = $exam->show_result_immediately;
        $colors = ['var(--blue)', 'var(--green)', 'var(--orange)', 'var(--purple)', 'var(--sakura-deep)'];
    @endphp
    <div class="page-head">
        <div>
            <h1>Hasil {{ $exam->title }}</h1>
            <p>{{ $exam->level->testType->name }} {{ $exam->level->code }} · Tanggal ujian: {{ $attempt->finished_at?->translatedFormat('d F Y') }}</p>
        </div>
    </div>

    @if ($attempt->stopped_by)
        <div class="alert info">Ujian ini dihentikan oleh pengawas{{ $attempt->stop_reason ? " ({$attempt->stop_reason})" : "" }}. Jawaban yang sudah tersimpan tetap dinilai.</div>
    @endif

    @if (! $show)
        <div class="hasil-hero neutral">
            <div class="l"><div class="mark">✓</div><div><h2>Jawaban terkirim</h2><p>Nilai akan diumumkan oleh sensei.</p></div></div>
        </div>
    @elseif ($attempt->isPendingReview())
        <div class="hasil-hero neutral">
            <div class="l"><div class="mark">⏳</div><div><h2>Menunggu penilaian esai</h2><p>Nilai di samping masih sementara dan bisa bertambah setelah sensei menilai jawaban esai.</p></div></div>
            <div class="r"><span>Nilai sementara</span><b>{{ number_format($score, 0) }}<small>/100</small></b></div>
        </div>
    @elseif ($passed === true)
        <div class="hasil-hero">
            <div class="l"><div class="mark">✓</div><div><h2>Selamat! Anda Lulus</h2><p>Nilai minimal {{ $exam->pass_score }}. Terus tingkatkan kemampuan Anda.</p></div></div>
            <div class="r"><span>Nilai Total</span><b>{{ number_format($score, 0) }}<small>/100</small></b></div>
        </div>
    @elseif ($passed === false)
        <div class="hasil-hero fail">
            <div class="l"><div class="mark">△</div><div><h2>Belum lulus</h2><p>Nilai minimal {{ $exam->pass_score }}. Pelajari lagi bagian yang nilainya rendah, lalu coba ujian berikutnya.</p></div></div>
            <div class="r"><span>Nilai Total</span><b>{{ number_format($score, 0) }}<small>/100</small></b></div>
        </div>
    @else
        <div class="hasil-hero">
            <div class="l"><div class="mark">✓</div><div><h2>Ujian selesai</h2><p>{{ $attempt->correct_count }} dari {{ $attempt->question_count }} soal dijawab benar.</p></div></div>
            <div class="r"><span>Nilai Total</span><b>{{ number_format($score, 0) }}<small>/100</small></b></div>
        </div>
    @endif

    <div class="grid cols-2" style="align-items:start">
        <div class="card">
            <div class="card-head"><h2>Rekap nilai per bagian</h2></div>
            @if ($show)
                @foreach ($attempt->sections as $i => $s)
                    <div class="kompetensi-row">
                        <div class="lbl">{{ $s->examSection->section->name }}
                            <small>{{ $s->correct_count }}/{{ $s->question_count }} benar @if ($s->submitted_by === 'timeout')· waktu habis @elseif ($s->submitted_by === 'proctor')· dihentikan pengawas @endif</small>
                        </div>
                        <div class="kbar"><span style="width: {{ (float) $s->score }}%; background: {{ $colors[$i % count($colors)] }}"></span></div>
                        <div class="val">{{ number_format((float) $s->score, 0) }}</div>
                    </div>
                @endforeach
                <p class="muted" style="font-size:12px;margin-top:8px">Nilai = persentase jawaban benar (0–100).</p>
            @else
                <p class="muted">Rincian nilai akan tampil setelah diumumkan sensei.</p>
            @endif
            <div class="btnrow" style="flex-wrap:wrap">
                @if ($exam->showsReview() && ! $attempt->isPendingReview())
                    <a class="btn secondary" href="{{ route('student.attempts.review', $attempt) }}">📖 Lihat Pembahasan</a>
                @endif
                @if ($attempt->certificate)
                    <a class="btn gold" href="{{ route('certificates.show', $attempt->certificate) }}">🏅 Download Sertifikat</a>
                @endif
                @php($remaining = $exam->remainingAttemptsFor(auth()->user()))
                @if ($exam->isOpen() && $remaining !== 0)
                    <form method="POST" action="{{ route('student.join') }}" style="display:inline">
                        @csrf <input type="hidden" name="code" value="{{ $exam->code }}">
                        <button class="btn" type="submit">↻ Ulangi Ujian @if ($remaining !== null)<small>(sisa {{ $remaining }} kali)</small>@endif</button>
                    </form>
                @else
                    <a class="btn" href="{{ route('dashboard') }}">Ke beranda</a>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Riwayat ujian</h2></div>
            @forelse ($history as $h)
                @php($hp = $h->exam->passed($h->score !== null ? (float) $h->score : null))
                <a class="list-row" href="{{ route('student.attempts.result', $h) }}" style="text-decoration:none;color:inherit">
                    <div @class(['ic', 'green' => $hp !== false, 'orange' => $hp === false])>{{ $hp === false ? '△' : '✓' }}</div>
                    <div class="info"><p>{{ $h->exam->title }}</p><span>{{ $h->finished_at?->translatedFormat('d M Y') }} · Nilai {{ $h->score !== null ? number_format((float) $h->score, 0) : '–' }}</span></div>
                </a>
            @empty
                <p class="muted">Ini ujian pertama Anda.</p>
            @endforelse
        </div>
    </div>
@endsection
