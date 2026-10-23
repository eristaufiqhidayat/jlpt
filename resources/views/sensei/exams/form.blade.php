@extends('layouts.app')
@section('title', $exam->exists ? 'Edit ujian' : 'Ujian baru')
@section('content')
    <div class="page-head">
        <h1>{{ $exam->exists ? 'Edit ujian' : 'Ujian baru' }}</h1>
        <a class="btn secondary" href="{{ $exam->exists ? route('sensei.exams.show', $exam) : route('sensei.exams.index') }}">Kembali</a>
    </div>

    @unless ($exam->exists)
        <form method="GET" class="card inline-form">
            <div class="field" style="flex:1">
                <label for="pick_level">Level ujian</label>
                <select id="pick_level" name="level_id" onchange="this.form.submit()">
                    @foreach ($levels as $l)
                        <option value="{{ $l->id }}" @selected($level?->id === $l->id)>{{ $l->testType->name }} {{ $l->code }}</option>
                    @endforeach
                </select>
            </div>
            <noscript><button class="btn secondary">Pilih</button></noscript>
        </form>
    @endunless

    @if (! $level)
        <div class="alert warn">Belum ada level dengan bagian soal.</div>
    @else
    <form method="POST" action="{{ $exam->exists ? route('sensei.exams.update', $exam) : route('sensei.exams.store') }}">
        @csrf
        @if ($exam->exists) @method('PUT') @endif
        <input type="hidden" name="level_id" value="{{ $level->id }}">

        <div class="grid cols-2" style="align-items:start">
            <div class="card">
                <h2>{{ $level->testType->name }} {{ $level->code }}</h2>
                <div class="field">
                    <label for="title">Judul ujian</label>
                    <input id="title" name="title" type="text" value="{{ old('title', $exam->title ?? "Latihan {$level->testType->name} {$level->code}") }}" required>
                </div>
                <div class="field">
                    <label for="description">Keterangan untuk student (opsional)</label>
                    <textarea id="description" name="description" rows="2">{{ old('description', $exam->description) }}</textarea>
                </div>
                <div class="row">
                    <div class="field">
                        <label for="opens_at">Dibuka mulai</label>
                        <input id="opens_at" name="opens_at" type="datetime-local" value="{{ old('opens_at', $exam->opens_at?->format('Y-m-d\TH:i')) }}">
                    </div>
                    <div class="field">
                        <label for="closes_at">Ditutup pada</label>
                        <input id="closes_at" name="closes_at" type="datetime-local" value="{{ old('closes_at', $exam->closes_at?->format('Y-m-d\TH:i')) }}">
                    </div>
                </div>
                <div class="hint muted" style="margin:-8px 0 12px">Kosongkan jika ujian bisa dikerjakan kapan saja selama statusnya "Dibuka".</div>
                <div class="row">
                    <div class="field">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="draft" @selected(old('status', $exam->status->value) === 'draft')>Draft</option>
                            <option value="open" @selected(old('status', $exam->status->value) === 'open')>Dibuka</option>
                            <option value="closed" @selected(old('status', $exam->status->value) === 'closed')>Ditutup</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="audio_play_limit">Batas putar audio</label>
                        <select id="audio_play_limit" name="audio_play_limit">
                            <option value="">Bebas (latihan)</option>
                            @foreach ([1, 2, 3] as $n)
                                <option value="{{ $n }}" @selected((string) old('audio_play_limit', $exam->audio_play_limit) === (string) $n)>{{ $n }}× {{ $n === 1 ? '(seperti JLPT asli)' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label for="pass_score">Nilai minimal lulus (opsional)</label>
                    <input id="pass_score" name="pass_score" type="number" min="1" max="100" value="{{ old('pass_score', $exam->pass_score) }}" placeholder="mis. 60" style="max-width:160px">
                    <div class="hint">Bila diisi, student melihat status "Lulus" / "Belum lulus" di halaman hasil.</div>
                </div>
                <div class="field">
                    <label for="audience">Untuk siapa</label>
                    <select id="audience" name="audience">
                        @foreach (\App\Models\Exam::AUDIENCES as $key => $label)<option value="{{ $key }}" @selected(old('audience', $exam->audience ?: 'internal') === $key)>{{ $label }}</option>@endforeach
                    </select>
                    <div class="hint">Siswa LPK masuk lewat LMS; peserta tes umum (berbayar) lewat domain tes online. Kode ujian hanya bisa dipakai oleh akun yang sesuai.</div>
                </div>
                <div class="field">
                    <input type="hidden" name="is_paid" value="0">
                    <label class="check"><input type="checkbox" name="is_paid" value="1" id="is_paid" @checked(old('is_paid', $exam->is_paid))> Ujian berbayar</label>
                </div>
                <div class="field" id="price-field">
                    <label for="price">Harga (Rp)</label>
                    <input id="price" name="price" type="number" min="0" step="1000" value="{{ old('price', $exam->price ?: '') }}">
                </div>
                <div class="field">
                    <input type="hidden" name="show_result_immediately" value="0">
                    <label class="check"><input type="checkbox" name="show_result_immediately" value="1" @checked(old('show_result_immediately', $exam->show_result_immediately))> Tampilkan nilai ke student begitu selesai</label>
                    <input type="hidden" name="shuffle_options" value="0">
                    <label class="check"><input type="checkbox" name="shuffle_options" value="1" @checked(old('shuffle_options', $exam->shuffle_options))> Acak urutan pilihan jawaban</label>
                </div>
            </div>

            <div class="card">
                <h2>Bagian ujian</h2>
                <p class="muted">Soal diambil acak dari Pelatihan Soal (yang berstatus "Siap pakai") saat student mulai. Durasi berlaku per bagian.</p>
                <div class="table-wrap"><table>
                    <tr><th></th><th>Bagian</th><th class="num">Menit</th><th class="num">Jumlah soal</th><th class="num">Urutan</th></tr>
                    @foreach ($rows as $row)
                        @php($sid = $row->section->id)
                        <tr>
                            <td>
                                <input type="hidden" name="sections[{{ $sid }}][enabled]" value="0">
                                <input type="checkbox" name="sections[{{ $sid }}][enabled]" value="1" @checked(old("sections.$sid.enabled", $row->enabled)) aria-label="Pakai {{ $row->section->name }}">
                            </td>
                            <td>
                                {{ $row->section->name }}<br>
                                <small class="{{ $row->available < $row->count ? 'error-text' : 'muted' }}">Tersedia {{ $row->available }} soal</small>
                            </td>
                            <td class="num"><input type="number" name="sections[{{ $sid }}][duration]" min="1" max="300" value="{{ old("sections.$sid.duration", $row->duration) }}" style="width:80px"></td>
                            <td class="num"><input type="number" name="sections[{{ $sid }}][count]" min="1" max="200" value="{{ old("sections.$sid.count", $row->count) }}" style="width:80px"></td>
                            <td class="num"><input type="number" name="sections[{{ $sid }}][order]" min="0" max="50" value="{{ old("sections.$sid.order", $row->order) }}" style="width:64px"></td>
                        </tr>
                    @endforeach
                </table></div>
                <p class="muted" style="margin-top:10px">Jika soal tersedia lebih sedikit dari jumlah yang diminta, student mendapat semua soal yang ada.</p>
            </div>
        </div>
        <button class="btn big" type="submit">{{ $exam->exists ? 'Simpan perubahan' : 'Buat ujian' }}</button>
    </form>
    @endif
@endsection

@push('scripts')
<script>
(function () {
    const cb = document.getElementById('is_paid'), f = document.getElementById('price-field');
    if (!cb) return;
    const t = () => f.style.display = cb.checked ? '' : 'none';
    cb.addEventListener('change', t); t();
})();
</script>
@endpush
