@extends('layouts.app')
@section('title', 'Kelas Saya')
@section('content')
    <div class="page-head"><div><h1>Kelas Saya</h1><p>Jadwal kelas dan rekap kehadiran.</p></div></div>
    <div class="grid cols-3" style="margin-bottom:16px">
        <div class="stat"><div class="ic">🏫</div><div><p class="label">Kelas</p><p class="value">{{ $classrooms->count() }}</p></div></div>
        <div class="stat"><div class="ic orange">📅</div><div><p class="label">Jadwal mendatang</p><p class="value">{{ $upcoming->count() }}</p></div></div>
        <div class="stat"><div class="ic green">✅</div><div><p class="label">Kehadiran</p><p class="value">{{ $rate !== null ? $rate.'%' : '–' }}</p></div></div>
    </div>
    @forelse ($classrooms as $c)
        <div class="card">
            <div class="list-row" style="border:0;padding:0">
                <div class="ic">🏫</div>
                <div class="info"><p>{{ $c->name }}</p><span>{{ $c->level?->code ?? 'Semua level' }} · Instruktur: {{ $c->sensei?->name ?? '–' }} · {{ $c->students_count }} peserta</span></div>
            </div>
            @if ($c->description)<p class="muted" style="font-size:13px;margin:8px 0 0">{{ $c->description }}</p>@endif
        </div>
    @empty
        <div class="card"><p class="muted">Anda belum terdaftar di kelas mana pun. Hubungi admin.</p></div>
    @endforelse
    <div class="grid cols-2" style="align-items:start">
        <div class="card">
            <div class="card-head"><h2>Jadwal mendatang</h2></div>
            @forelse ($upcoming as $s)
                <div class="list-row">
                    <div @class(['ic', 'orange' => $s->date->isToday()])>{{ $s->date->format('d') }}</div>
                    <div class="info"><p>{{ $s->topic ?: 'Kelas' }}</p><span>{{ $s->date->translatedFormat('l, d M Y') }} · {{ $s->timeRange() }} · {{ $s->classroom->name }}@if ($s->sensei) · {{ $s->sensei->name }}@endif</span></div>
                    @if ($s->date->isToday())<span class="badge">Hari ini</span>@endif
                </div>
            @empty
                <p class="muted">Belum ada jadwal.</p>
            @endforelse
        </div>
        <div class="card">
            <div class="card-head"><h2>Riwayat kehadiran</h2></div>
            @forelse ($attendance as $a)
                <div class="doc-row">
                    <span>{{ $a->session->date->translatedFormat('d M Y') }} · {{ $a->session->topic ?: $a->session->classroom->name }}</span>
                    <span @class(['doc-status', 'selesai' => $a->status === 'hadir', 'proses' => in_array($a->status, ['izin', 'sakit']), 'belum' => $a->status === 'alpa'])>{{ \App\Models\Attendance::STATUSES[$a->status] ?? $a->status }}</span>
                </div>
            @empty
                <p class="muted">Belum ada data kehadiran.</p>
            @endforelse
        </div>
    </div>
@endsection
