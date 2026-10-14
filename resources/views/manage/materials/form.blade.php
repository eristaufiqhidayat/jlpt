@extends('layouts.app')
@section('title', ($material->exists ? 'Edit ' : 'Tambah ').($material->isExtra() ? 'materi tambahan' : 'materi pakem'))
@section('container_class', 'narrow')
@section('content')
    @php($kind = old('kind', $material->kind))
    <div class="page-head"><h1>{{ ($material->exists ? 'Edit ' : 'Tambah ').($material->isExtra() ? 'materi tambahan' : 'materi') }}</h1><a class="btn secondary" href="{{ route('manage.materials.index') }}">Kembali</a></div>
    <form method="POST" enctype="multipart/form-data" class="card" action="{{ $material->exists ? route('manage.materials.update', $material) : route('manage.materials.store') }}">
        @csrf @if ($material->exists) @method('PUT') @endif

        @if ($canChooseKind)
            <div class="field"><span style="display:block;font-weight:700;font-size:12.5px;margin-bottom:6px">Jenis materi</span>
                <label class="check"><input type="radio" name="kind" value="core" @checked($kind === 'core') onchange="setKind(this.value)"> Materi pakem (kurikulum inti, untuk semua kelas)</label>
                <label class="check"><input type="radio" name="kind" value="extra" @checked($kind === 'extra') onchange="setKind(this.value)"> Materi tambahan (untuk satu kelas)</label>
            </div>
        @elseif ($material->isExtra())
            <div class="alert info">Materi tambahan hanya terlihat oleh peserta kelas tujuan. Buka/tutup aksesnya lewat Status.</div>
        @else
            <div class="alert info">Materi pakem: terlihat oleh peserta yang kurikulumnya sudah terbuka (kelasnya sekarang atau sebelumnya).</div>
        @endif

        <div class="field"><label for="title">Judul</label><input id="title" name="title" type="text" value="{{ old('title', $material->title) }}" required maxlength="150"></div>

        <div data-kind="core" @if ($kind !== 'core') hidden @endif>
            <div class="row">
                <div class="field"><label for="curriculum_id">Kurikulum</label>
                    <select id="curriculum_id" name="curriculum_id"><option value="">Materi umum (tanpa kurikulum)</option>
                        @foreach ($curricula as $c)<option value="{{ $c->id }}" @selected((int) old('curriculum_id', $material->curriculum_id) === $c->id)>{{ $c->group }} · {{ $c->name }}@if ($c->chapterRange()) ({{ $c->chapterRange() }})@endif</option>@endforeach
                    </select>
                    <div class="hint">Menentukan lock: peserta hanya membuka kurikulum kelasnya dan yang sebelumnya.</div></div>
                <div class="field" style="max-width:130px"><label for="chapter_no">Nomor bab</label><input id="chapter_no" name="chapter_no" type="number" min="1" max="999" value="{{ old('chapter_no', $material->chapter_no) }}"></div>
            </div>
            <div class="row">
                <div class="field"><label for="chapter">Judul bab</label><input id="chapter" name="chapter" type="text" value="{{ old('chapter', $material->chapter) }}" placeholder="mis. ～ことができます"></div>
                <div class="field"><label for="level_id">Level (untuk materi umum)</label>
                    <select id="level_id" name="level_id"><option value="">Semua level</option>
                        @foreach ($levels as $lv)<option value="{{ $lv->id }}" @selected((int) old('level_id', $material->level_id) === $lv->id)>{{ $lv->testType->code }} {{ $lv->code }}</option>@endforeach
                    </select></div>
            </div>
        </div>

        <div data-kind="extra" @if ($kind !== 'extra') hidden @endif>
            <div class="row">
                <div class="field"><label for="classroom_id">Kelas tujuan</label>
                    <select id="classroom_id" name="classroom_id"><option value="">– pilih kelas –</option>
                        @foreach ($classrooms as $cl)<option value="{{ $cl->id }}" @selected((int) old('classroom_id', $material->classroom_id) === $cl->id)>{{ $cl->name }}</option>@endforeach
                    </select>
                    @if ($classrooms->isEmpty())<div class="error-text">Anda belum memegang kelas. Minta admin menempatkan Anda sebagai wali/pengajar kelas.</div>@endif</div>
                <div class="field"><label for="available_until">Tayang sampai (opsional)</label><input id="available_until" name="available_until" type="date" value="{{ old('available_until', $material->available_until?->format('Y-m-d')) }}">
                    <div class="hint">Setelah tanggal ini materi otomatis tersembunyi dari peserta.</div></div>
            </div>
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
                    <option value="draft" @selected(old('status', $material->status) === 'draft')>Ditutup / draft (belum tampil)</option>
                    <option value="published" @selected(old('status', $material->status) === 'published')>Dibuka / terbit</option>
                </select></div>
        </div>
        <button class="btn">Simpan</button>
    </form>
    @if ($canChooseKind)
        <script>
            function setKind(kind) {
                document.querySelectorAll('[data-kind]').forEach(function (el) { el.hidden = el.dataset.kind !== kind; });
            }
        </script>
    @endif
@endsection
