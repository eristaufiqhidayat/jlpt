@extends('layouts.app')
@section('title', 'Nilai & Riwayat')
@section('content')
    <div class="page-head"><div><h1>Nilai &amp; Riwayat</h1><p>Semua ujian yang pernah Anda ikuti.</p></div></div>
    <div class="card flush">
        <div class="table-wrap"><table>
            <tr><th>Ujian</th><th>Level</th><th>Tanggal</th><th>Status</th><th class="num">Nilai</th><th></th></tr>
            @forelse ($attempts as $a)
                @php($passed = $a->exam->passed($a->score !== null ? (float) $a->score : null))
                <tr>
                    <td><strong>{{ $a->exam->title }}</strong></td>
                    <td>{{ $a->exam->level->testType->code }} {{ $a->exam->level->code }}</td>
                    <td>{{ $a->started_at?->translatedFormat('d M Y H:i') }}</td>
                    <td>
                        @if ($a->status->value === 'ongoing')<span class="badge info">Sedang dikerjakan</span>
                        @elseif ($a->isPendingReview())<span class="badge warn">Menunggu nilai esai</span>
                        @elseif ($passed === true)<span class="badge ok">Lulus</span>
                        @elseif ($passed === false)<span class="badge err">Belum lulus</span>
                        @else<span class="badge ok">Selesai</span>@endif
                    </td>
                    <td class="num"><strong>{{ $a->score !== null && $a->exam->show_result_immediately ? number_format((float) $a->score, 0) : '–' }}</strong></td>
                    <td class="actions">
                        @if ($a->status->value === 'ongoing')
                            <a class="btn small" href="{{ route('student.attempts.show', $a) }}">Lanjutkan</a>
                        @else
                            <a class="btn small secondary" href="{{ route('student.attempts.result', $a) }}">Detail</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Belum ada ujian.</td></tr>
            @endforelse
        </table></div>
    </div>
    {{ $attempts->links() }}
@endsection
