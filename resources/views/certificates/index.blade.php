@extends('layouts.app')
@section('title', 'Sertifikat')
@section('content')
    <div class="page-head"><div><h1>Sertifikat Saya</h1><p>Sertifikat digital diterbitkan otomatis saat Anda lulus ujian.</p></div></div>
    @if ($certificates->isEmpty())
        <div class="card"><p class="muted">Belum ada sertifikat. Lulus ujian yang memiliki nilai minimal untuk mendapatkan sertifikat.</p></div>
    @else
        <div class="grid cols-2">
            @foreach ($certificates as $c)
                <div class="card">
                    <div class="list-row" style="border:0;padding:0">
                        <div class="ic purple">🏅</div>
                        <div class="info"><p>{{ $c->exam->title }}</p><span>{{ $c->number }} · {{ $c->issued_at->translatedFormat('d F Y') }} · Nilai {{ number_format((float) $c->score, 0) }}</span></div>
                        <a class="btn small" href="{{ route('certificates.show', $c) }}">Lihat</a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
