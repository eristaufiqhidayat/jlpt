@extends('layouts.app')
@section('title', $question->exists ? 'Edit soal #'.$question->id : 'Soal baru')
@section('content')
    <div class="page-head">
        <h1>{{ $question->exists ? 'Edit soal #'.$question->id : 'Soal baru' }}</h1>
        <div>
            @if ($question->exists)
                <a class="btn secondary" href="{{ route('sensei.questions.preview', ['question' => $question->id]) }}" target="_blank" rel="noopener">👁 Pratinjau seperti peserta</a>
            @endif
            <a class="btn secondary" href="{{ route('sensei.questions.index', ['section_id' => $question->section_id]) }}">Kembali</a>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data"
          action="{{ $question->exists ? route('sensei.questions.update', $question) : route('sensei.questions.store') }}">
        @csrf
        @if ($question->exists) @method('PUT') @endif

        <div class="grid cols-2" style="align-items:start">
            <div>
                <div class="card">
                    <div class="row">
                        <div class="field">
                            <label for="section_id">Bagian</label>
                            <select id="section_id" name="section_id" required>
                                <option value="">— pilih —</option>
                                @foreach ($sections as $group => $items)
                                    <optgroup label="{{ $group }}">
                                        @foreach ($items as $id => $name)
                                            <option value="{{ $id }}" @selected(old('section_id', $question->section_id) == $id)>{{ $group }} · {{ $name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="type">Jenis soal</label>
                            <select id="type" name="type" required>
                                @foreach ($types as $value => $label)
                                    <option value="{{ $value }}" @selected(old('type', $question->type?->value) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="field">
                            <label for="mondai">Kelompok (mondai)</label>
                            <input id="mondai" name="mondai" type="text" value="{{ old('mondai', $question->mondai) }}" placeholder="mis. 問題1">
                        </div>
                        <div class="field">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="draft" @selected(old('status', $question->status?->value) === 'draft')>Draft</option>
                                <option value="published" @selected(old('status', $question->status?->value) === 'published')>Siap pakai</option>
                                <option value="archived" @selected(old('status', $question->status?->value) === 'archived')>Diarsipkan</option>
                            </select>
                            <div class="hint">Hanya soal "Siap pakai" yang diambil untuk ujian.</div>
                        </div>
                        <div class="field">
                            <label for="usage">Dipakai untuk</label>
                            <select id="usage" name="usage">
                                @foreach (\App\Models\Question::USAGES as $value => $label)
                                    <option value="{{ $value }}" @selected(old('usage', $question->usage ?: 'practice') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="hint">Soal "Tes online" hanya diambil ujian bersumber soal tes, dan kunci serta pembahasannya tidak pernah ditampilkan ke peserta.</div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="field">
                            <label for="passage_id">Bacaan / audio bersama</label>
                            <select id="passage_id" name="passage_id">
                                <option value="">— tidak ada —</option>
                                @foreach ($passages as $p)
                                    <option value="{{ $p->id }}" @selected(old('passage_id', $question->passage_id) == $p->id)>
                                        #{{ $p->id }} {{ $p->title ?: \Illuminate\Support\Str::limit(\App\Support\JpText::plain($p->content), 30) }} ({{ $p->section->level->code }})
                                    </option>
                                @endforeach
                            </select>
                            <div class="hint"><a href="{{ route('sensei.passages.create') }}" target="_blank">+ Buat bacaan baru</a></div>
                        </div>
                        <div class="field">
                            <label for="number_in_passage">Urutan di bacaan</label>
                            <input id="number_in_passage" name="number_in_passage" type="number" min="1" value="{{ old('number_in_passage', $question->number_in_passage) }}">
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="field">
                        <label for="stem">Teks soal</label>
                        <textarea id="stem" name="stem" rows="4" lang="ja">{{ old('stem', $question->stem) }}</textarea>
                        <div class="hint">Furigana: <code>{漢字|かんじ}</code> · garis bawah: <code>__kata__</code> · tempat kosong: <code>（　　）</code> · soal ★: <code>＿＿ ＿＿ ★ ＿＿</code></div>
                    </div>
                    <div class="field">
                        <label>Pratinjau</label>
                        <div class="preview" id="preview" lang="ja"></div>
                    </div>
                    <div class="row">
                        <div class="field">
                            <label for="image">Gambar soal</label>
                            <input id="image" name="image" type="file" accept="image/*">
                            @if ($question->image_path)
                                <img src="{{ route('media', $question->image_path) }}" alt="" class="q-img" style="max-height:120px;margin-top:6px">
                                <label class="check"><input type="checkbox" name="remove_image" value="1"> Hapus gambar</label>
                            @endif
                        </div>
                        <div class="field">
                            <label for="audio">Audio soal (choukai)</label>
                            <input id="audio" name="audio" type="file" accept="audio/*">
                            @if ($question->audio_path)
                                <audio controls src="{{ route('media', $question->audio_path) }}" style="width:100%;margin-top:6px"></audio>
                                <label class="check"><input type="checkbox" name="remove_audio" value="1"> Hapus audio</label>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <div class="card" id="options-card">
                    <h2>Pilihan jawaban</h2>
                    <p class="muted">Pilih jawaban benar dengan tombol bulat. Pilihan boleh berupa teks, gambar, atau keduanya. Untuk choukai yang pilihannya hanya diucapkan, cukup isi 1, 2, 3, 4.</p>
                    @foreach ($options as $i => $opt)
                        <div class="option-row">
                            <div class="num">{{ $i }}</div>
                            <input type="text" name="options[{{ $i }}][content]" value="{{ old("options.$i.content", $opt?->content) }}" lang="ja" aria-label="Pilihan {{ $i }}">
                            <div>
                                <input type="file" name="options[{{ $i }}][image]" accept="image/*" aria-label="Gambar pilihan {{ $i }}" style="font-size:.8rem">
                                @if ($opt?->image_path)
                                    <img src="{{ route('media', $opt->image_path) }}" alt="" style="max-height:48px;display:block">
                                    <label class="check" style="font-size:.8rem"><input type="checkbox" name="options[{{ $i }}][remove_image]" value="1"> hapus</label>
                                @endif
                            </div>
                            <label class="check" title="Jawaban benar"><input type="radio" name="correct" value="{{ $i }}" @checked((int) old('correct', $opt?->is_correct ? $i : 0) === $i)> benar</label>
                        </div>
                    @endforeach
                </div>

                <div class="card" id="star-card">
                    <h2>Soal ★ (menyusun kalimat)</h2>
                    <div class="row">
                        <div class="field">
                            <label for="star_position">Posisi ★ (1–4)</label>
                            <input id="star_position" name="star_position" type="number" min="1" max="4" value="{{ old('star_position', $question->star_position) }}">
                        </div>
                        <div class="field">
                            <label for="correct_order">Urutan benar</label>
                            <input id="correct_order" name="correct_order" type="text" value="{{ old('correct_order', $question->correct_order) }}" placeholder="mis. 2-4-1-3">
                            <div class="hint">Untuk pembahasan; kunci tetap pilihan yang jatuh di posisi ★.</div>
                        </div>
                    </div>
                </div>

                <div class="card" id="essay-card">
                    <h2>Esai</h2>
                    <div class="field">
                        <label for="answer_reference">Contoh jawaban benar (untuk sensei saat menilai)</label>
                        <textarea id="answer_reference" name="answer_reference" rows="2" lang="ja">{{ old('answer_reference', $question->answer_reference) }}</textarea>
                    </div>
                </div>

                <div class="card">
                    <div class="field">
                        <label for="explanation">Pembahasan (opsional)</label>
                        <textarea id="explanation" name="explanation" rows="3">{{ old('explanation', $question->explanation) }}</textarea>
                    </div>
                </div>

                <p>
                    <button class="btn" type="submit" name="after" value="list">Simpan</button>
                    <button class="btn secondary" type="submit" name="after" value="new">Simpan &amp; buat soal berikutnya</button>
                </p>
            </div>
        </div>
    </form>

    @if ($question->exists)
        <form method="POST" action="{{ route('sensei.questions.destroy', $question) }}" onsubmit="return confirm('Hapus soal ini? Soal yang sudah dipakai ujian hanya akan diarsipkan.')">
            @csrf @method('DELETE')
            <button class="btn danger small">Hapus soal</button>
        </form>
    @endif
@endsection

@push('scripts')
<script>
(function () {
    const esc = s => s.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const render = s => esc(s)
        .replace(/\{([^{}|]+)\|([^{}|]+)\}/g, '<ruby>$1<rt>$2</rt></ruby>')
        .replace(/__(.+?)__/g, '<u>$1</u>')
        .replace(/\n/g, '<br>');
    const stem = document.getElementById('stem'), preview = document.getElementById('preview');
    const update = () => preview.innerHTML = render(stem.value) || '<span class="muted">(kosong)</span>';
    stem.addEventListener('input', update); update();

    const type = document.getElementById('type');
    const toggle = () => {
        const t = type.value;
        document.getElementById('options-card').style.display = t === 'essay' ? 'none' : '';
        document.getElementById('star-card').style.display = t === 'star' ? '' : 'none';
        document.getElementById('essay-card').style.display = t === 'essay' ? '' : 'none';
    };
    type.addEventListener('change', toggle); toggle();
})();
</script>
@endpush
