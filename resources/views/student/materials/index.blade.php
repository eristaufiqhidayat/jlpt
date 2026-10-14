@extends('layouts.app')
@section('title', 'Materi')
@section('content')
    @php($doneHere = $groups->flatten(1)->filter(fn ($m) => $done->has($m->id))->count())
    <div class="page-head">
        <div><h1>Materi Belajar</h1><p>Bisa dibuka kapan saja dan berulang. Kurikulum berikutnya terbuka setelah kamu naik kelas.</p></div>
    </div>

    @if ($tabs->isNotEmpty())
        <nav class="tabs" aria-label="Kurikulum">
            @foreach ($tabs as $t)
                <a href="{{ route('student.materials', ['tab' => $t['key']]) }}" @class(['active' => $tab && $tab['key'] === $t['key'], 'locked' => $t['locked']])
                   @if ($tab && $tab['key'] === $t['key']) aria-current="page" @endif>@if ($t['locked'])🔒 @endif{{ $t['label'] }}</a>
            @endforeach
        </nav>
    @endif

    @if (! $hasClass)
        <div class="alert info">Kamu belum terdaftar di kelas, jadi materi kurikulum belum terbuka. Hubungi admin untuk penempatan kelas.</div>
    @endif

    @if ($tab && $tab['locked'])
        <div class="card locked-box">
            <p style="font-size:28px;margin:0">🔒</p>
            <h2>{{ $tab['curricula']->pluck('name')->implode(', ') }} belum terbuka</h2>
            <p class="muted" style="margin:0">Materi ini terbuka setelah kamu masuk kelas {{ $tab['curricula']->count() > 1 ? $tab['label'] : $tab['curricula']->first()->name }}. Selesaikan dulu kurikulum kelasmu sekarang.</p>
        </div>
    @else
        @if ($total > 0)
            <div class="card" style="padding:14px 18px">
                <p style="margin:0 0 8px;font-size:13px">{{ $doneHere }} dari {{ $total }} materi selesai</p>
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
                                @if ($m->available_until) · tersedia s.d. {{ $m->available_until->translatedFormat('d M Y') }} @endif
                            </span></div>
                        <span class="btn small secondary">{{ $done->has($m->id) ? 'Ulangi' : 'Mulai' }}</span>
                    </a>
                @endforeach
            </div>
        @empty
            <div class="card"><p class="muted" style="margin:0">Belum ada materi di sini. Sensei atau Admin Pendidikan akan menambahkannya.</p></div>
        @endforelse
    @endif
@endsection
