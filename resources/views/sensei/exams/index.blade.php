@extends('layouts.app')
@section('title', 'Ujian')
@section('content')
    <div class="page-head">
        <h1>Ujian</h1>
        <a class="btn" href="{{ route('sensei.exams.create') }}">+ Ujian baru</a>
    </div>
    <div class="card" style="padding:0">
        <div class="table-wrap"><table>
            <tr><th>Judul</th><th>Level</th><th>Kode</th><th>Untuk</th><th>Biaya</th><th>Status</th><th class="num">Peserta</th>@if (auth()->user()->isAcademicAdmin())<th>Sensei</th>@endif<th></th></tr>
            @forelse ($exams as $exam)
                <tr>
                    <td><a href="{{ route('sensei.exams.show', $exam) }}">{{ $exam->title }}</a></td>
                    <td>{{ $exam->level->testType->code }} {{ $exam->level->code }}</td>
                    <td><span class="code">{{ $exam->code }}</span></td>
                    <td>@if ($exam->audience === 'public')<span class="badge warn">Tes umum</span>@elseif ($exam->audience === 'both')<span class="badge">Keduanya</span>@else<span class="badge ok">Siswa LPK</span>@endif</td>
                    <td>{{ $exam->is_paid ? 'Rp'.number_format($exam->price, 0, ',', '.') : 'Gratis' }}</td>
                    <td>@include('sensei.exams._status')</td>
                    <td class="num">{{ $exam->attempts_count }}</td>
                    @if (auth()->user()->isAcademicAdmin())<td>{{ $exam->sensei->name }}</td>@endif
                    <td class="actions"><a class="btn small secondary" href="{{ route('sensei.exams.edit', $exam) }}">Edit</a></td>
                </tr>
            @empty
                <tr><td colspan="9" class="muted">Belum ada ujian.</td></tr>
            @endforelse
        </table></div>
    </div>
    {{ $exams->links() }}
@endsection
