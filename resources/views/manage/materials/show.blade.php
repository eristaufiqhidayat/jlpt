@extends('layouts.app')
@section('title', $material->title)
@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $material->title }}</h1>
            <p>
                @if ($material->isExtra())
                    Materi tambahan · {{ $material->classroom?->name ?? '–' }} · oleh {{ $material->author?->name ?? '–' }}
                @else
                    @if ($material->curriculum){{ $material->curriculum->name }} · @endif{{ $material->chapterLabel() }}
                @endif
                @if ($material->level) · {{ $material->level->code }}@endif
                @if ($material->duration_minutes) · {{ $material->duration_minutes }} menit @endif
                · {!! $material->status === 'published' ? '<span class="badge ok">'.($material->isExtra() ? 'Dibuka' : 'Terbit').'</span>' : '<span class="badge">'.($material->isExtra() ? 'Ditutup' : 'Draft').'</span>' !!}
            </p>
        </div>
        <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="button" class="btn secondary" data-present>⛶ Layar penuh</button>
            @if ($editable)<a class="btn secondary" href="{{ route('manage.materials.edit', $material) }}">Edit</a>@endif
            <a class="btn secondary" href="{{ route('manage.materials.index', ['tab' => $tab]) }}">Kembali</a>
        </div>
    </div>

    @unless ($editable)
        <div class="alert info">Mode lihat: materi ini bisa dipakai untuk mengajar, tetapi tidak bisa diubah atau dihapus.@unless ($material->isExtra()) Perubahan materi pakem dilakukan Admin Pendidikan.@endunless</div>
    @endunless

    <div class="materi-layout">
        <div id="present-area" class="present-area">
            @if ($material->youtubeEmbed())
                <div class="video-frame"><iframe src="{{ $material->youtubeEmbed() }}" title="{{ $material->title }}" allowfullscreen allow="accelerometer; encrypted-media; picture-in-picture"></iframe></div>
            @elseif ($material->video_url)
                <div class="card"><a href="{{ $material->video_url }}" target="_blank" rel="noopener">▶ Buka video materi</a></div>
            @endif
            @php($videos = $material->filesOfType('video'))
            @foreach ($videos as $video)
                @if ($videos->count() > 1)<p class="video-title">🎬 {{ $loop->iteration }}. {{ $video->label() }}</p>@endif
                <div class="video-frame"><video controls preload="metadata" src="{{ route('media', $video->path) }}"></video></div>
            @endforeach

            @php($audios = $material->filesOfType('audio'))
            @if ($audios->isNotEmpty())
                <div class="card audio-list">
                    <div class="card-head"><h2>🎧 Audio</h2></div>
                    @foreach ($audios as $a)
                        <div class="audio-item"><p>{{ $a->label() }}</p><audio controls preload="none" src="{{ route('media', $a->path) }}"></audio></div>
                    @endforeach
                </div>
            @endif

            @php($docs = $material->files->whereIn('type', ['pdf', 'ppt']))
            @if ($docs->isNotEmpty() || $material->exam)
                <div class="resource-btns">
                    @foreach ($material->filesOfType('pdf') as $f)
                        <a class="btn secondary" href="{{ route('media', $f->path) }}" target="_blank">📄 {{ $f->label() }}</a>
                    @endforeach
                    @foreach ($material->filesOfType('ppt') as $f)
                        <a class="btn secondary" href="{{ route('media', $f->path) }}">📊 {{ $f->label() }}</a>
                    @endforeach
                    @if ($material->exam)
                        <a class="btn gold" href="{{ route('sensei.exams.show', $material->exam) }}">✏️ Latihan: {{ $material->exam->title }} ({{ $material->exam->code }})</a>
                    @endif
                </div>
                @if ($material->filesOfType('ppt')->isNotEmpty())
                    <p class="muted" style="font-size:12px;margin:-4px 0 14px">File PPT/PPTX diunduh lalu dibuka dengan PowerPoint/Google Slides.</p>
                @endif
            @endif

            @if ($material->description)
                <div class="card">
                    <div class="card-head"><h2>Ringkasan materi</h2></div>
                    <div lang="ja" style="line-height:1.8">@jp($material->description)</div>
                </div>
            @endif

            @if (! $material->description && $material->files->isEmpty() && ! $material->video_url)
                <div class="card"><p class="muted" style="margin:0">Materi ini belum berisi video, file, atau ringkasan.</p></div>
            @endif
        </div>
        <div>
            <div class="card">
                <div class="card-head"><h2>{{ $material->isExtra() ? 'Materi kelas ini' : $material->chapterLabel() }}</h2></div>
                @foreach ($siblings as $m)
                    <a @class(['materi-list-item', 'active' => $m->id === $material->id]) href="{{ route('manage.materials.show', $m) }}">
                        <div class="num">{{ $loop->iteration }}</div>
                        <div class="info"><p>{{ $m->title }}</p><span>{{ $m->category ?: 'Materi' }}@if ($m->status !== 'published') · draft @endif</span></div>
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    <script>
        // Layar penuh untuk ditampilkan di proyektor/TV saat mengajar.
        document.querySelector('[data-present]').addEventListener('click', function () {
            var area = document.getElementById('present-area');
            if (document.fullscreenElement) { document.exitFullscreen(); return; }
            if (area.requestFullscreen) { area.requestFullscreen(); }
        });
    </script>
@endsection
