@extends('layouts.app')
@section('title', 'Materi')
@section('content')
    @php($doneHere = $groups->flatten(1)->filter(fn ($m) => $done->has($m->id))->count())
    <div class="page-head">
        <div><h1>Materi Belajar</h1><p>{{ $doneHere }} dari {{ $total }} materi selesai.</p></div>
    </div>
    @if ($total > 0)
        <div class="card" style="padding:14px 18px">
            <div class="bar-track"><span style="width: {{ round($doneHere / $total * 100) }}%"></span></div>
        </div>
    @endif
    @forelse ($groups as $chapter => $items)
        <div class="card">
            <div class="card-head"><h2>{{ $chapter }}</h2><span class="muted" style="font-size:12px">{{ $items->filter(fn ($m) => $done->has($m->id))->count() }}/{{ $items->count() }} selesai</span></div>
            @foreach ($items as $m)
                <a class="materi-list-item" href="{{ route('student.materials.show', $m) }}">
                    <div @class(['num', 'done' => $done->has($m->id)])>{{ $done->has($m->id) ? '✓' : $loop->iteration }}</div>
                    <div class="info"><p>{{ $m->title }}</p>
                        <span>{{ $m->category ?: 'Materi' }}@if ($m->duration_minutes) · {{ $m->duration_minutes }} menit @endif
                            @if ($m->video_url || $m->video_path) · 🎬 video @endif @if ($m->pdf_path) · PDF @endif @if ($m->ppt_path) · PPT @endif @if ($m->exam_id) · latihan @endif
                        </span></div>
                    <span class="btn small secondary">{{ $done->has($m->id) ? 'Ulangi' : 'Mulai' }}</span>
                </a>
            @endforeach
        </div>
    @empty
        <div class="card"><p class="muted">Belum ada materi untuk level Anda. Sensei akan menambahkannya.</p></div>
    @endforelse
@endsection
