@extends('layouts.app')
@section('title', $material->title)
@section('content')
    @php($isDone = $done->has($material->id))
    <div class="page-head">
        <div><h1>{{ $material->title }}</h1><p>@if ($material->curriculum){{ $material->curriculum->name }} · @endif{{ $material->chapterLabel() }}@if ($material->level) · {{ $material->level->code }}@endif @if ($material->duration_minutes) · {{ $material->duration_minutes }} menit @endif</p></div>
        <a class="btn secondary" href="{{ route('student.materials') }}">Semua materi</a>
    </div>
    <div class="materi-layout">
        <div>
            @if ($material->youtubeEmbed())
                <div class="video-frame"><iframe src="{{ $material->youtubeEmbed() }}" title="{{ $material->title }}" allowfullscreen allow="accelerometer; encrypted-media; picture-in-picture"></iframe></div>
            @elseif ($material->video_url)
                <div class="card"><a href="{{ $material->video_url }}" target="_blank" rel="noopener">▶ Buka video materi</a></div>
            @endif
            @php($videos = $material->filesOfType('video'))
            @foreach ($videos as $video)
                @if ($videos->count() > 1)<p class="video-title">🎬 {{ $loop->iteration }}. {{ $video->label() }}</p>@endif
                <div class="video-frame"><video controls controlsList="nodownload" preload="metadata" src="{{ route('media', $video->path) }}"></video></div>
            @endforeach

            @php($audios = $material->filesOfType('audio'))
            @if ($audios->isNotEmpty())
                <div class="card audio-list">
                    <div class="card-head"><h2>🎧 Audio</h2></div>
                    @foreach ($audios as $a)
                        <div class="audio-item"><p>{{ $a->label() }}</p><audio controls preload="none" controlsList="nodownload" src="{{ route('media', $a->path) }}"></audio></div>
                    @endforeach
                </div>
            @endif

            <div class="resource-btns">
                @foreach ($material->filesOfType('pdf') as $f)
                    <a class="btn secondary" href="{{ route('media', $f->path) }}" target="_blank">📄 {{ $material->filesOfType('pdf')->count() > 1 ? $f->label() : 'PDF Materi' }}</a>
                @endforeach
                @foreach ($material->filesOfType('ppt') as $f)
                    <a class="btn secondary" href="{{ route('media', $f->path) }}">📊 {{ $material->filesOfType('ppt')->count() > 1 ? $f->label() : 'Slide PPT' }}</a>
                @endforeach
                @if ($material->exam && $material->exam->isOpen())
                    <form method="POST" action="{{ route('student.join') }}" style="display:inline">
                        @csrf <input type="hidden" name="code" value="{{ $material->exam->code }}">
                        <button class="btn gold" type="submit">✏️ Latihan Soal</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('student.materials.complete', $material) }}" style="display:inline">
                    @csrf
                    <button @class(['btn', 'secondary' => $isDone]) type="submit">{{ $isDone ? '✓ Sudah selesai (batalkan)' : 'Tandai selesai' }}</button>
                </form>
            </div>

            @if ($material->description)
                <div class="card">
                    <div class="card-head"><h2>Ringkasan materi</h2></div>
                    <div lang="ja" style="line-height:1.8">@jp($material->description)</div>
                </div>
            @endif
        </div>
        <div>
            <div class="card">
                <div class="card-head"><h2>{{ $material->isExtra() ? 'Materi dari sensei' : $material->chapterLabel() }}</h2></div>
                @foreach ($siblings as $m)
                    <a @class(['materi-list-item', 'active' => $m->id === $material->id]) href="{{ route('student.materials.show', $m) }}">
                        <div @class(['num', 'done' => $done->has($m->id)])>{{ $done->has($m->id) ? '✓' : $loop->iteration }}</div>
                        <div class="info"><p>{{ $m->title }}</p><span>{{ $m->category ?: 'Materi' }}</span></div>
                    </a>
                @endforeach
            </div>
            @if ($others->isNotEmpty())
                <div class="card">
                    <div class="card-head"><h2>Materi lainnya</h2></div>
                    @foreach ($others as $m)
                        <a class="materi-list-item" href="{{ route('student.materials.show', $m) }}">
                            <div class="num">{{ $m->icon() }}</div>
                            <div class="info"><p>{{ $m->title }}</p><span>{{ $m->chapterLabel() }}</span></div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
