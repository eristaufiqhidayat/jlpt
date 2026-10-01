@extends('layouts.app')
@section('title', 'Arsip #'.$file->legacy_id)
@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $file->original_filename ?: $file->stored_filename }}</h1>
            <span class="muted">Arsip #{{ $file->legacy_id }} · {{ $file->section_name }} · {{ $file->question_count }} soal · {{ $file->duration_minutes }} menit</span>
        </div>
        <div>
            <a class="btn secondary" href="{{ route('sensei.legacy.index') }}">Kembali</a>
            @if ($file->section_id)
                <a class="btn" href="{{ route('sensei.questions.create', ['section_id' => $file->section_id]) }}" target="_blank">+ Input soal ke bank</a>
            @endif
        </div>
    </div>

    @if ($file->issues)
        <div class="alert warn">
            Catatan impor:
            <ul>@foreach ($file->issues as $issue)<li>{{ $issue }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="grid cols-2" style="grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr); align-items:start">
        <div class="card" style="padding:0;overflow:hidden">
            @if ($hasPdf && str_contains((string) $file->file_mime, 'pdf'))
                <iframe src="{{ route('sensei.legacy.pdf', $file) }}" title="PDF soal" style="width:100%;height:80vh;border:0"></iframe>
            @elseif ($hasPdf)
                <p style="padding:20px"><a href="{{ route('sensei.legacy.pdf', $file) }}" target="_blank">Buka file ({{ $file->file_mime }})</a></p>
            @else
                <p style="padding:20px" class="muted">File soal tidak tersedia di server.</p>
            @endif
        </div>

        <div>
            <div class="card">
                <h2>Kunci jawaban</h2>
                <p>@include('sensei.legacy._key_status', ['status' => $file->key_status]) <small class="muted">{{ $file->valid_key_count }}/{{ $file->question_count }} nomor</small></p>
                <form method="POST" action="{{ route('sensei.legacy.keys', $file) }}">
                    @csrf @method('PUT')
                    <div class="table-wrap" style="max-height:60vh;overflow-y:auto"><table>
                        <tr><th>No.</th><th>Lama</th><th>Kunci</th>@if ($hasAudio)<th>Audio</th>@endif</tr>
                        @foreach ($rows as $row)
                            @php($invalid = $row->key && ! $row->key->is_valid)
                            <tr @if ($invalid || ! $row->key) style="background:var(--warn-bg)" @endif>
                                <td>{{ $row->number }}</td>
                                <td>
                                    {{ $row->key?->raw_key ?? '–' }}
                                    @if ($row->duplicates)<span class="badge warn">dobel</span>@endif
                                    @if ($row->key?->edited_by)<span class="badge info">diperbaiki</span>@endif
                                </td>
                                <td>
                                    <select name="keys[{{ $row->number }}]" style="width:auto">
                                        <option value="">–</option>
                                        @foreach ([1 => 'A', 2 => 'B', 3 => 'C', 4 => 'D'] as $v => $l)
                                            <option value="{{ $v }}" @selected($row->key?->is_valid && $row->key->option_label === $v)>{{ $v }} ({{ $l }})</option>
                                        @endforeach
                                    </select>
                                    @if ($row->key?->explanation)
                                        <details style="margin-top:4px"><summary class="muted" style="font-size:.8rem">pembahasan</summary><div style="font-size:.85rem">{{ $row->key->explanation }}</div></details>
                                    @endif
                                </td>
                                @if ($hasAudio)
                                    <td>
                                        @if ($row->key?->audio_path)
                                            <audio controls preload="none" src="{{ route('sensei.legacy.audio', $row->key) }}" style="width:180px;height:32px"></audio>
                                        @elseif ($row->key?->audio_filename)
                                            <small class="muted" title="{{ $row->key->audio_filename }}">file tidak ada</small>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </table></div>
                    <p style="margin-top:12px"><button class="btn">Simpan kunci</button></p>
                </form>
            </div>

            <div class="card">
                <h2>Paket lama yang memakai file ini</h2>
                @forelse ($file->exams as $e)
                    <div>{{ $e->jenis_tes }} {{ $e->kelas }} · paket {{ $e->ulevel }} <small class="muted">{{ $e->nama_tes }}</small></div>
                @empty
                    <p class="muted">Paket sudah tidak ada di database lama.</p>
                @endforelse
            </div>
        </div>
    </div>
@endsection
