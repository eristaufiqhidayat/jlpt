@extends('layouts.app')
@section('title', 'Kelas & Jadwal')
@section('content')
    <div class="page-head">
        <div><h1>Kelas & Jadwal</h1><p>Kelola kelas, peserta, jadwal, dan absensi.</p></div>
        <a class="btn" href="{{ route('manage.classes.create') }}">+ Tambah kelas</a>
    </div>
    <div class="grid cols-3">
        @forelse ($classrooms as $c)
            <a class="card" href="{{ route('manage.classes.show', $c) }}" style="text-decoration:none;color:inherit;display:block">
                <div class="card-head"><h2>{{ $c->name }}</h2>{!! $c->is_active ? '<span class="badge ok">Aktif</span>' : '<span class="badge">Nonaktif</span>' !!}</div>
                <p class="muted" style="margin:0;font-size:13px">@if ($c->curriculum){{ $c->curriculum->name }} · @endif{{ $c->level?->code ?? 'Semua level' }} · Instruktur: {{ $c->sensei?->name ?? '–' }}</p>
                <p style="margin:10px 0 0;font-size:13px">👥 {{ $c->students_count }} peserta · 📅 {{ $c->upcoming_count }} jadwal mendatang</p>
            </a>
        @empty
            <div class="card"><p class="muted">Belum ada kelas.</p></div>
        @endforelse
    </div>
@endsection
