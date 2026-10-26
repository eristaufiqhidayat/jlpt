@extends('layouts.app')
@section('title', $passage->exists ? 'Edit bacaan' : 'Bacaan baru')
@section('container_class', 'narrow')
@section('content')
    <div class="page-head">
        <h1>{{ $passage->exists ? 'Edit bacaan #'.$passage->id : 'Bacaan / audio baru' }}</h1>
        <a class="btn secondary" href="{{ route('sensei.passages.index') }}">Kembali</a>
    </div>
    <form method="POST" enctype="multipart/form-data" class="card"
          action="{{ $passage->exists ? route('sensei.passages.update', $passage) : route('sensei.passages.store') }}">
        @csrf
        @if ($passage->exists) @method('PUT') @endif
        <div class="row">
            <div class="field">
                <label for="section_id">Bagian</label>
                <select id="section_id" name="section_id" required>
                    <option value="">— pilih —</option>
                    @foreach ($sections as $group => $items)
                        <optgroup label="{{ $group }}">
                            @foreach ($items as $id => $name)
                                <option value="{{ $id }}" @selected(old('section_id', $passage->section_id) == $id)>{{ $group }} · {{ $name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="published" @selected(old('status', $passage->status?->value) === 'published')>Siap pakai</option>
                    <option value="draft" @selected(old('status', $passage->status?->value) === 'draft')>Draft</option>
                    <option value="archived" @selected(old('status', $passage->status?->value) === 'archived')>Diarsipkan</option>
                </select>
            </div>
        </div>
        <div class="field">
            <label for="title">Judul (untuk memudahkan mencari)</label>
            <input id="title" name="title" type="text" value="{{ old('title', $passage->title) }}" placeholder="mis. N4 読解 メール 1">
        </div>
        <div class="field">
            <label for="content">Isi bacaan</label>
            <textarea id="content" name="content" rows="10" lang="ja">{{ old('content', $passage->content) }}</textarea>
            <div class="hint">Notasi sama dengan soal: <code>{漢字|かんじ}</code>, <code>__kata__</code>. Untuk soal isian dalam wacana, tulis penanda seperti <code>（21）</code>.</div>
        </div>
        <div class="row">
            <div class="field">
                <label for="image">Gambar (pengumuman, tabel, dll.)</label>
                <input id="image" name="image" type="file" accept="image/*">
                @if ($passage->image_path)
                    <img src="{{ route('media', $passage->image_path) }}" alt="" class="q-img" style="max-height:120px;margin-top:6px">
                    <label class="check"><input type="checkbox" name="remove_image" value="1"> Hapus gambar</label>
                @endif
            </div>
            <div class="field">
                <label for="audio">Audio (choukai)</label>
                <input id="audio" name="audio" type="file" accept="audio/*,.mp3,.m4a,.wav,.ogg">
                @if ($passage->audio_path)
                    <audio controls src="{{ route('media', $passage->audio_path) }}" style="width:100%;margin-top:6px"></audio>
                    <label class="check"><input type="checkbox" name="remove_audio" value="1"> Hapus audio</label>
                @endif
            </div>
        </div>
        <button class="btn" type="submit">Simpan</button>
    </form>

    @if ($passage->exists && $passage->questions->isNotEmpty())
        <div class="card">
            <h2>Soal pada bacaan ini</h2>
            <ol>
                @foreach ($passage->questions as $q)
                    <li><a href="{{ route('sensei.questions.edit', $q) }}">#{{ $q->id }}</a> {{ \Illuminate\Support\Str::limit(\App\Support\JpText::plain($q->stem), 80) }}</li>
                @endforeach
            </ol>
        </div>
    @endif
@endsection
