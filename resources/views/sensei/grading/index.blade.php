@extends('layouts.app')
@section('title', 'Penilaian esai')
@section('content')
    <div class="page-head"><h1>Penilaian esai</h1></div>

    <form method="GET" class="filters">
        <div class="field" style="min-width:260px">
            <label for="exam_id">Ujian</label>
            <select id="exam_id" name="exam_id" onchange="this.form.submit()">
                <option value="">Semua ujian</option>
                @foreach ($exams as $e)
                    <option value="{{ $e->id }}" @selected(request('exam_id') == $e->id)>{{ $e->title }} [{{ $e->code }}]</option>
                @endforeach
            </select>
        </div>
    </form>

    @forelse ($items as $item)
        @php($attempt = $item->attemptSection->attempt)
        <div class="card">
            <div class="q-head">
                <span><strong>{{ $attempt->student->name }}</strong> · {{ $attempt->exam->title }}</span>
                <small class="muted">{{ $item->answered_at?->format('d/m H:i') }}</small>
            </div>
            <div class="q-stem" lang="ja">@jp($item->question->stem)</div>
            <div class="grid cols-2">
                <div>
                    <label>Jawaban student</label>
                    <div class="preview" lang="ja">{{ $item->answer_text }}</div>
                </div>
                <div>
                    <label>Contoh jawaban benar</label>
                    <div class="preview" lang="ja">{{ $item->question->answer_reference ?: '—' }}</div>
                </div>
            </div>
            <form method="POST" action="{{ route('sensei.grading.store', $item) }}" class="inline-form" style="margin-top:12px">
                @csrf
                <div class="field" style="flex:1">
                    <label for="fb{{ $item->id }}">Catatan (opsional)</label>
                    <input id="fb{{ $item->id }}" name="feedback" type="text">
                </div>
                <button class="btn success" name="is_correct" value="1">✓ Benar</button>
                <button class="btn danger" name="is_correct" value="0">✗ Salah</button>
            </form>
        </div>
    @empty
        <div class="card"><p class="muted">Tidak ada esai yang menunggu penilaian.</p></div>
    @endforelse
    {{ $items->links() }}
@endsection
