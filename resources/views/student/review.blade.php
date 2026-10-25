@extends('layouts.app')
@section('title', 'Pembahasan')
@section('content')
    <div class="page-head">
        <div><h1>Pembahasan {{ $attempt->exam->title }}</h1><p>Nilai {{ number_format((float) $attempt->score, 0) }} · {{ $attempt->correct_count }}/{{ $attempt->question_count }} benar</p></div>
        <a class="btn secondary" href="{{ route('student.attempts.result', $attempt) }}">Kembali ke hasil</a>
    </div>
    @foreach ($attempt->sections as $s)
        <div class="card">
            <div class="card-head"><h2>{{ $s->examSection->section->name }}</h2><span class="badge">{{ $s->correct_count }}/{{ $s->question_count }} benar</span></div>
            @php($lastPassage = null)
            @foreach ($s->questions as $i => $item)
                @php($q = $item->question)
                @if ($q->isTestOnly())
                    {{-- Soal yang kini ditandai "Tes online" tidak ditampilkan supaya isi dan kuncinya tidak bocor. --}}
                    <div class="review-q"><p class="muted" style="margin:0"><strong>{{ $i + 1 }}.</strong> Soal ini sekarang dipakai untuk tes online, jadi isi dan kuncinya tidak ditampilkan.</p></div>
                    @continue
                @endif
                <div class="review-q">
                    @if ($q->passage && $q->passage_id !== $lastPassage)
                        @php($lastPassage = $q->passage_id)
                        @if ($q->passage->content)<div class="passage" lang="ja">@jp($q->passage->content)</div>@endif
                    @endif
                    <p style="margin:0 0 6px"><strong>{{ $i + 1 }}.</strong>
                        @if ($item->correct_option_id === null)
                            <span class="badge">esai</span>
                        @elseif ($item->is_correct)
                            <span class="doc-status selesai">Benar</span>
                        @elseif ($item->selected_option_id)
                            <span class="doc-status proses">Salah</span>
                        @else
                            <span class="doc-status belum">Tidak dijawab</span>
                        @endif
                    </p>
                    @if ($q->stem)<p class="q-stem" lang="ja" style="margin:0 0 6px">@jp($q->stem)</p>@endif
                    @if ($q->image_path)<img class="q-img" src="{{ route('media', $q->image_path) }}" alt="">@endif
                    @if ($item->correct_option_id === null)
                        <div class="review-opt">Jawaban Anda: {{ $item->answer_text ?: '–' }}</div>
                        @if ($item->feedback)<p class="muted" style="font-size:13px">Catatan sensei: {{ $item->feedback }}</p>@endif
                    @else
                        @foreach ($q->options as $opt)
                            <div @class(['review-opt', 'correct' => $opt->id === $item->correct_option_id, 'wrong' => $opt->id === $item->selected_option_id && $opt->id !== $item->correct_option_id]) lang="ja">
                                {{ $opt->label }}. @jp($opt->content)
                                @if ($opt->id === $item->selected_option_id) <small>(jawaban Anda)</small>@endif
                            </div>
                        @endforeach
                    @endif
                    @if ($q->explanation)
                        <div class="alert info" style="margin-top:8px;font-size:13px"><strong>Pembahasan:</strong> @jp($q->explanation)</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach
@endsection
