@extends('layouts.app')
@section('title', 'Program Jepang')
@section('content')
    @php($P = \App\Support\ProgramJepang::class)
    <div class="page-head">
        <div><h1>Monitoring Program Jepang</h1>@unless ($editable)<p>Tahapan dan dokumen keberangkatan ke Jepang.</p>@endunless</div>
        @if (! auth()->user()->isStudent())<a class="btn secondary" href="{{ route('manage.program.index') }}">Semua peserta</a>@endif
    </div>

    <div class="card">
        <div class="mon-profile">
            <div class="avatar lg" aria-hidden="true">{{ $student->initials() }}</div>
            <div>
                <h2>{{ $student->name }}</h2>
                <p>
                    NIS: {{ $student->profile?->nis ?? '–' }}
                    · Kelas: {{ $student->classrooms->pluck('name')->implode(', ') ?: '–' }}
                    · Level: {{ $student->profile?->level?->code ?? '–' }}
                    · Program: {{ $student->profile?->program_name ?? '–' }}
                </p>
            </div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:700;margin-bottom:6px"><span>Progress keseluruhan</span><span>{{ $progress }}%</span></div>
        <div class="bar-track"><span style="width: {{ $progress }}%"></span></div>
    </div>

    @if ($editable)
        <form method="POST" action="{{ route('manage.program.update', $student) }}" enctype="multipart/form-data">
            @csrf @method('PUT')
    @endif

    @if ($editable)
        <div class="card">
            <div class="card-head"><h2>Tahapan</h2></div>
            @include('program._timeline', ['stages' => $stages])
        </div>
    @endif
    <div class="grid" style="grid-template-columns: minmax(0, 1.6fr) minmax(280px, 1fr); align-items:start">
        <div class="card">
            <div class="card-head"><h2>{{ $editable ? 'Ubah tahapan' : 'Tahapan' }}</h2></div>
            @unless ($editable)@include('program._timeline', ['stages' => $stages])@endunless
            @if ($editable)
                <div class="table-wrap" style="margin-top:12px"><table>
                    <tr><th>Tahap</th><th>Status</th><th>Tanggal</th><th>Catatan</th></tr>
                    @foreach ($P::STAGES as $key => $label)
                        @php($st = $stages->get($key))
                        <tr>
                            <td><strong>{{ $label }}</strong></td>
                            <td><select style="min-width:105px" name="stages[{{ $key }}][status]" aria-label="Status {{ $label }}">
                                @foreach ($P::STATUSES as $v => $l)<option value="{{ $v }}" @selected(($st?->status ?? 'belum') === $v)>{{ $l }}</option>@endforeach
                            </select></td>
                            <td><input type="date" name="stages[{{ $key }}][date]" value="{{ $st?->date?->format('Y-m-d') }}" aria-label="Tanggal {{ $label }}"></td>
                            <td><input type="text" name="stages[{{ $key }}][note]" value="{{ $st?->note }}" aria-label="Catatan {{ $label }}"></td>
                        </tr>
                    @endforeach
                </table></div>
            @else
                @foreach ($stages->filter(fn ($s) => $s->note) as $s)
                    <p class="muted" style="font-size:12.5px;margin:4px 0"><strong>{{ $P::STAGES[$s->stage] ?? $s->stage }}:</strong> {{ $s->note }}</p>
                @endforeach
            @endif
        </div>

        <div class="card">
            <div class="card-head"><h2>Dokumen</h2></div>
            @foreach ($P::DOCUMENTS as $key => $label)
                @php($doc = $documents->get($key))
                @php($status = $doc?->status ?? 'belum')
                <div class="doc-row" style="flex-wrap:wrap">
                    <span>{{ $label }}
                        @if ($doc?->file_path)<a href="{{ route('program.document', $doc) }}" target="_blank" style="font-size:11.5px"> · lihat file</a>@endif
                        @if ($doc?->note && ! $editable)<br><small>{{ $doc->note }}</small>@endif
                    </span>
                    @if ($editable)
                        <select name="documents[{{ $key }}][status]" style="width:auto;min-width:105px" aria-label="Status {{ $label }}">
                            @foreach ($P::STATUSES as $v => $l)<option value="{{ $v }}" @selected($status === $v)>{{ $l }}</option>@endforeach
                        </select>
                        <div style="width:100%;display:flex;gap:8px;margin-top:6px">
                            <input type="text" name="documents[{{ $key }}][note]" value="{{ $doc?->note }}" placeholder="catatan" aria-label="Catatan {{ $label }}">
                            <input type="file" name="files[{{ $key }}]" accept=".pdf,image/*" aria-label="File {{ $label }}" style="max-width:190px">
                        </div>
                    @else
                        <span class="doc-status {{ $status }}">{{ $P::STATUSES[$status] }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    @if ($editable)
            <button class="btn big">Simpan perubahan</button>
        </form>
    @endif
@endsection
