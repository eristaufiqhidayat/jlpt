@extends('layouts.app')
@section('title', $material->exists ? 'Edit materi' : 'Tambah materi')
@section('container_class', 'narrow')
@section('content')
    <div class="page-head"><h1>{{ $material->exists ? 'Edit materi' : 'Tambah materi' }}</h1><a class="btn secondary" href="{{ route('manage.materials.index') }}">Kembali</a></div>
    <form method="POST" enctype="multipart/form-data" class="card" action="{{ $material->exists ? route('manage.materials.update', $material) : route('manage.materials.store') }}">
        @csrf @if ($material->exists) @method('PUT') @endif
        <div class="field"><label for="title">Judul</label><input id="title" name="title" type="text" value="{{ old('title', $material->title) }}" required maxlength="150"></div>
        <div class="row">
            <div class="field"><label for="level_id">Level</label>
                <select id="level_id" name="level_id"><option value="">Semua level</option>
                    @foreach ($levels as $lv)<option value="{{ $lv->id }}" @selected((int) old('level_id', $material->level_id) === $lv->id)>{{ $lv->testType->code }} {{ $lv->code }}</option>@endforeach
                </select></div>
            <div class="field"><label for="chapter">Bab</label><input id="chapter" name="chapter" type="text" value="{{ old('chapter', $material->chapter) }}" placeholder="mis. Bab 5 – Partikel"></div>
        </div>
        <div class="row">
            <div class="field"><label for="category">Kategori</label><input id="category" name="category" type="text" list="cat-list" value="{{ old('category', $material->category) }}">
                <datalist id="cat-list"><option>Bunpou</option><option>Kanji</option><option>Kotoba</option><option>Choukai</option><option>Dokkai</option><option>Kaiwa</option></datalist></div>
            <div class="field"><label for="duration_minutes">Durasi (menit)</label><input id="duration_minutes" name="duration_minutes" type="number" min="1" max="600" value="{{ old('duration_minutes', $material->duration_minutes) }}"></div>
            <div class="field"><label for="sort_order">Urutan</label><input id="sort_order" name="sort_order" type="number" min="0" max="999" value="{{ old('sort_order', $material->sort_order) }}"></div>
        </div>
        <div class="field"><label for="description">Ringkasan / isi materi</label>
            <textarea id="description" name="description" rows="6">{{ old('description', $material->description) }}</textarea>
            <div class="hint">Furigana: <code>{漢字|かんじ}</code>, garis bawah: <code>__kata__</code>.</div></div>
        <div class="field"><label for="video_url">Tautan video (YouTube)</label><input id="video_url" name="video_url" type="url" value="{{ old('video_url', $material->video_url) }}" placeholder="https://www.youtube.com/watch?v=...">
            @error('video_url')<div class="error-text">{{ $message }}</div>@enderror</div>
        @foreach (['video' => ['Upload video (mp4/webm, maks. 500 MB)', 'video_path', 'video/*'], 'pdf' => ['File PDF', 'pdf_path', 'application/pdf'], 'ppt' => ['File PPT/PPTX', 'ppt_path', '.ppt,.pptx,.pdf']] as $input => [$label, $col, $accept])
            <div class="field"><label for="{{ $input }}">{{ $label }}</label>
                <input id="{{ $input }}" name="{{ $input }}" type="file" accept="{{ $accept }}">
                @if ($material->$col)
                    <div class="hint">File sekarang: <a href="{{ route('media', $material->$col) }}" target="_blank">{{ basename($material->$col) }}</a>
                        · <label class="check" style="display:inline-flex"><input type="checkbox" name="remove_{{ $input }}" value="1"> hapus</label></div>
                @endif
                @error($input)<div class="error-text">{{ $message }}</div>@enderror
            </div>
        @endforeach
        <div class="row">
            <div class="field"><label for="exam_id">Latihan soal (ujian terbuka)</label>
                <select id="exam_id" name="exam_id"><option value="">– tidak ada –</option>
                    @foreach ($exams as $e)<option value="{{ $e->id }}" @selected((int) old('exam_id', $material->exam_id) === $e->id)>{{ $e->title }} ({{ $e->code }})</option>@endforeach
                </select></div>
            <div class="field"><label for="status">Status</label>
                <select id="status" name="status">
                    <option value="draft" @selected(old('status', $material->status) === 'draft')>Draft (belum tampil)</option>
                    <option value="published" @selected(old('status', $material->status) === 'published')>Terbit</option>
                </select></div>
        </div>
        <button class="btn">Simpan</button>
    </form>
@endsection
