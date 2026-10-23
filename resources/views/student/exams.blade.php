@extends('layouts.app')
@section('title', 'Ujian')
@section('content')
    <div class="page-head"><div><h1>Ujian</h1><p>Pilih klasifikasi ujian lalu masukkan kode ujian, atau lanjutkan ujian yang sedang berjalan.</p></div></div>
    <div class="grid cols-2" style="align-items:start">
        <div>
            <div class="card">
                <div class="card-head"><h2>Masuk ujian</h2></div>
                @include('student._join_form')
            </div>
            @if ($ongoing->isNotEmpty() || $pendingPayments->isNotEmpty())
                <div class="card">
                    <div class="card-head"><h2>Perlu dilanjutkan</h2></div>
                    @foreach ($ongoing as $a)
                        <div class="list-row"><div class="ic">✏️</div><div class="info"><p>{{ $a->exam->title }}</p><span>Sedang dikerjakan</span></div>
                            <a class="btn small" href="{{ route('student.attempts.show', $a) }}">Lanjutkan</a></div>
                    @endforeach
                    @foreach ($pendingPayments as $p)
                        <div class="list-row"><div class="ic orange">💳</div><div class="info"><p>{{ $p->exam->title }}</p><span>Menunggu pembayaran · Rp{{ number_format($p->amount, 0, ',', '.') }}</span></div>
                            <a class="btn small gold" href="{{ route('student.payments.show', $p->exam->code) }}">Bayar</a></div>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="card">
            <div class="card-head"><h2>Ujian terakhir</h2><a href="{{ route('student.results') }}">Semua nilai</a></div>
            @forelse ($recent as $a)
                <a class="list-row" href="{{ route('student.attempts.result', $a) }}" style="text-decoration:none;color:inherit">
                    <div class="ic green">✓</div>
                    <div class="info"><p>{{ $a->exam->title }}</p><span>{{ $a->finished_at?->translatedFormat('d M Y') }} · {{ $a->exam->level->code }}</span></div>
                </a>
            @empty
                <p class="muted">Belum ada ujian.</p>
            @endforelse
        </div>
    </div>
@endsection
