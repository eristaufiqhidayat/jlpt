@extends('layouts.app')
@section('title', 'Arsip soal lama')
@section('content')
    <div class="page-head"><h1>Arsip soal lama</h1></div>
    <p class="muted">File PDF dan kunci jawaban dari aplikasi lama. Gunakan sebagai sumber saat menginput soal ke Pelatihan Soal, dan perbaiki kunci yang ditandai.</p>

    <form method="GET" class="filters">
        <div class="field">
            <label for="jenis_tes">Jenis tes</label>
            <select id="jenis_tes" name="jenis_tes">
                <option value="">Semua</option>
                @foreach ($types as $t)
                    <option value="{{ $t }}" @selected(request('jenis_tes') === $t)>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="status">Status kunci</label>
            <select id="status" name="status">
                <option value="">Semua</option>
                <option value="problem" @selected(request('status') === 'problem')>Perlu dicek</option>
                @foreach ($statuses as $st)
                    <option value="{{ $st->value }}" @selected(request('status') === $st->value)>{{ $st->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="flex:1">
            <label for="q">Cari kode / nama file</label>
            <input id="q" type="search" name="q" value="{{ request('q') }}">
        </div>
        <button class="btn secondary">Terapkan</button>
    </form>

    <div class="card" style="padding:0">
        <div class="table-wrap"><table>
            <tr><th>File</th><th>Paket lama</th><th>Bagian</th><th class="num">Soal</th><th>Kunci</th><th class="num">Sudah diinput</th><th></th></tr>
            @forelse ($files as $f)
                <tr>
                    <td>
                        {{ $f->original_filename ?: $f->stored_filename }}<br>
                        <small class="muted">#{{ $f->legacy_id }} · {{ $f->legacy_created_at?->format('d/m/Y') }}</small>
                        @unless ($f->file_path)<span class="badge err">file tidak ada</span>@endunless
                    </td>
                    <td>
                        @forelse ($f->exams->take(4) as $e)
                            <div><small>{{ $e->jenis_tes }} {{ $e->ulevel }}</small></div>
                        @empty
                            <small class="muted">tidak ada di m_exam</small>
                        @endforelse
                        @if ($f->exams->count() > 4)<small class="muted">+{{ $f->exams->count() - 4 }} lagi</small>@endif
                    </td>
                    <td>
                        @if ($f->section)
                            {{ $f->section->level->testType->code }} {{ $f->section->level->code }}<br><small>{{ $f->section->name }}</small>
                        @else
                            <small class="muted">{{ $f->section_name }}</small>
                        @endif
                    </td>
                    <td class="num">{{ $f->question_count }}</td>
                    <td>@include('sensei.legacy._key_status', ['status' => $f->key_status])<br><small class="muted">{{ $f->valid_key_count }}/{{ $f->question_count }}</small></td>
                    <td class="num">{{ $f->questions_count }}</td>
                    <td class="actions"><a class="btn small secondary" href="{{ route('sensei.legacy.show', $f) }}">Buka</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Arsip kosong. Jalankan <code>php artisan legacy:import</code> di server.</td></tr>
            @endforelse
        </table></div>
    </div>
    {{ $files->links() }}
@endsection
