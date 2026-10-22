@extends('layouts.app')
@section('title', $exam->title)
@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $exam->title }}</h1>
            <span class="muted">{{ $exam->level->testType->name }} {{ $exam->level->code }} · oleh {{ $exam->sensei->name }}</span>
        </div>
        <div>
            <a class="btn secondary" href="{{ route('sensei.exams.edit', $exam) }}">Edit</a>
            @foreach (['open' => 'Buka ujian', 'closed' => 'Tutup ujian'] as $st => $label)
                @if ($exam->status->value !== $st)
                    <form method="POST" action="{{ route('sensei.exams.status', $exam) }}" style="display:inline">
                        @csrf <input type="hidden" name="status" value="{{ $st }}">
                        <button class="btn {{ $st === 'open' ? 'success' : 'danger' }}">{{ $label }}</button>
                    </form>
                @endif
            @endforeach
        </div>
    </div>

    <div class="grid cols-4">
        <div class="stat"><div class="label">Kode ujian</div><div class="value code" style="font-size:1.6rem">{{ $exam->code }}</div></div>
        <div class="stat"><div class="label">Status</div><div class="value" style="font-size:1.2rem">@include('sensei.exams._status')</div></div>
        <div class="stat"><div class="label">Untuk</div><div class="value" style="font-size:1rem">{{ $exam->audienceLabel() }}</div></div>
        <div class="stat"><div class="label">Biaya</div><div class="value" style="font-size:1.2rem">{{ $exam->is_paid ? 'Rp'.number_format($exam->price, 0, ',', '.') : 'Gratis' }}</div></div>
        <div class="stat"><div class="label">Total waktu</div><div class="value" style="font-size:1.2rem">{{ $exam->totalDurationMinutes() }} menit</div></div>
    </div>

    <div class="card" style="margin-top:16px">
        <h2>Bagian</h2>
        <div class="table-wrap"><table>
            <tr><th>#</th><th>Bagian</th><th class="num">Menit</th><th class="num">Jumlah soal</th><th class="num">Soal tersedia di bank</th></tr>
            @foreach ($exam->sections as $i => $s)
                @php($avail = $available[$s->section_id] ?? 0)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $s->section->name }}</td>
                    <td class="num">{{ $s->duration_minutes }}</td>
                    <td class="num">{{ $s->question_count }}</td>
                    <td class="num">
                        @if ($avail === 0)
                            <span class="badge err">0 — ujian belum bisa dimulai</span>
                        @elseif ($avail < $s->question_count)
                            <span class="badge warn">{{ $avail }}</span>
                        @else
                            {{ $avail }}
                        @endif
                        <a href="{{ route('sensei.questions.create', ['section_id' => $s->section_id]) }}" class="btn small secondary">+ soal</a>
                    </td>
                </tr>
            @endforeach
        </table></div>
    </div>

    <div class="card">
        <div class="page-head" style="margin-bottom:8px">
            <h2 style="margin:0">Peserta</h2>
            @if ($attempts->total())
                <a class="btn secondary small" href="{{ route('sensei.exams.export', $exam) }}">⬇ Export nilai (.xlsx)</a>
            @endif
        </div>
        @if ($attempts->isEmpty())
            <p class="muted">Belum ada peserta.</p>
        @else
            <div class="table-wrap"><table>
                <tr><th>Student</th><th>Mulai</th><th>Status</th>
                    @foreach ($exam->sections as $s)<th class="num">{{ $s->section->name }}</th>@endforeach
                    <th class="num">Total</th></tr>
                @foreach ($attempts as $a)
                    @php($bySection = $a->sections->keyBy('exam_section_id'))
                    <tr>
                        <td>{{ $a->student->name }}<br><small>{{ $a->student->email }}</small></td>
                        <td>{{ $a->started_at?->format('d/m H:i') }}</td>
                        <td>
                            @switch($a->status->value)
                                @case('ongoing')<span class="badge info">Mengerjakan</span>@break
                                @case('pending_review')<span class="badge warn">Tunggu nilai esai</span>@break
                                @default<span class="badge ok">Selesai</span>
                            @endswitch
                        </td>
                        @foreach ($exam->sections as $s)
                            @php($as = $bySection->get($s->id))
                            <td class="num">{{ $as && $as->submitted_at ? $as->correct_count.'/'.$as->question_count : '–' }}</td>
                        @endforeach
                        <td class="num"><strong>{{ $a->score !== null ? number_format((float) $a->score, 1) : '–' }}</strong></td>
                    </tr>
                @endforeach
            </table></div>
            {{ $attempts->links() }}
        @endif
    </div>
@endsection
