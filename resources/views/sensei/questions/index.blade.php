@extends('layouts.app')
@section('title', 'Pelatihan Soal')
@section('content')
    <div class="page-head">
        <div><h1>Pelatihan Soal</h1><p>Kelola soal ujian untuk semua level.</p></div>
        <div>
            <a class="btn secondary" href="{{ route('sensei.questions.preview', request()->except('page')) }}" title="Lihat soal hasil filter seperti tampilan ujian peserta">👁 Pratinjau seperti peserta</a>
            <a class="btn secondary" href="{{ route('sensei.passages.index') }}">Bacaan &amp; audio bersama</a>
            <a class="btn secondary" href="{{ route('sensei.questions.import') }}">Import Excel</a>
            <a class="btn" href="{{ route('sensei.questions.create', array_filter(['level_id' => request('level_id')])) }}">+ Soal baru</a>
        </div>
    </div>

    <form method="GET" class="filters">
        <div class="field">
            <label for="level_id">Jenis Ujian</label>
            <select id="level_id" name="level_id">
                <option value="">Semua</option>
                @foreach ($levelGroups as $group => $items)
                    <optgroup label="{{ $group }}">
                        @foreach ($items as $id => $name)
                            <option value="{{ $id }}" @selected(request('level_id') == $id)>{{ $name }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="type">Jenis</label>
            <select id="type" name="type">
                <option value="">Semua</option>
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">Semua</option>
                <option value="published" @selected(request('status') === 'published')>Siap pakai</option>
                <option value="draft" @selected(request('status') === 'draft')>Draft</option>
                <option value="archived" @selected(request('status') === 'archived')>Diarsipkan</option>
            </select>
        </div>
        <div class="field">
            <label for="usage">Dipakai untuk</label>
            <select id="usage" name="usage">
                <option value="">Semua</option>
                @foreach (\App\Models\Question::USAGES as $value => $label)
                    <option value="{{ $value }}" @selected(request('usage') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="flex:1">
            <label for="q">Cari teks soal</label>
            <input id="q" type="search" name="q" value="{{ request('q') }}">
        </div>
        <button class="btn secondary">Terapkan</button>
    </form>

    <div class="card" style="padding:0">
        <div class="table-wrap"><table>
            <tr><th>#</th><th>Soal</th><th>Bagian</th><th>Jenis</th><th>Kunci</th><th>Status</th><th></th></tr>
            @forelse ($questions as $q)
                <tr>
                    <td>{{ $q->id }}</td>
                    <td style="max-width:420px">
                        @if ($q->mondai)<small class="muted">{{ $q->mondai }}</small><br>@endif
                        {{ \Illuminate\Support\Str::limit(\App\Support\JpText::plain($q->stem) ?: '(tanpa teks)', 90) }}
                        @if ($q->passage)<br><small class="muted">Bacaan: {{ $q->passage->title ?: '#'.$q->passage->id }}</small>@endif
                        @if ($q->image_path) <span class="badge">gambar</span>@endif
                        @if ($q->audio_path) <span class="badge">audio</span>@endif
                    </td>
                    <td>{{ $q->section->level->testType->code }} {{ $q->section->level->code }}<br><small>{{ $q->section->name }}</small></td>
                    <td><small>{{ $types[$q->type->value] }}</small></td>
                    <td>
                        @if ($q->type->value === 'essay')
                            <span class="badge info">manual</span>
                        @elseif ($q->correctOption)
                            <strong>{{ $q->correctOption->label }}</strong> <small class="muted">/ {{ $q->options_count }}</small>
                        @else
                            <span class="badge err">belum ada</span>
                        @endif
                    </td>
                    <td>@include('sensei.questions._status', ['status' => $q->status->value])
                        <br><small @class(['badge', 'warn' => $q->usage === 'test', 'info' => $q->usage === 'both'])>{{ $q->usageLabel() }}</small></td>
                    <td class="actions">
                        <a class="btn small secondary" href="{{ route('sensei.questions.preview', request()->except('page') + ['question' => $q->id]) }}" title="Pratinjau seperti peserta">Lihat</a>
                        <a class="btn small secondary" href="{{ route('sensei.questions.edit', $q) }}">Edit</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Belum ada soal dengan filter ini.</td></tr>
            @endforelse
        </table></div>
    </div>
    {{ $questions->links() }}
@endsection
