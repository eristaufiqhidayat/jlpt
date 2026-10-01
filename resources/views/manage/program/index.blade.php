@extends('layouts.app')
@section('title', 'Program Jepang')
@section('content')
    <div class="page-head"><div><h1>Monitoring Program Jepang</h1><p>Tahapan peserta dari pendaftaran sampai keberangkatan.</p></div></div>
    <form method="GET" class="filters">
        <div class="field"><label for="classroom_id">Kelas</label>
            <select id="classroom_id" name="classroom_id" onchange="this.form.submit()"><option value="">Semua</option>
                @foreach ($classrooms as $c)<option value="{{ $c->id }}" @selected((int) request('classroom_id') === $c->id)>{{ $c->name }}</option>@endforeach
            </select></div>
        <div class="field" style="flex:1"><label for="q">Cari nama / NIS</label><input id="q" type="search" name="q" value="{{ request('q') }}"></div>
        <button class="btn secondary">Cari</button>
    </form>
    <div class="card" style="padding:0">
        <div class="table-wrap"><table>
            <tr><th>Peserta</th><th>Kelas</th><th>Level</th><th>Tahap saat ini</th><th style="min-width:160px">Progress</th><th></th></tr>
            @forelse ($students as $st)
                @php($progress = \App\Support\ProgramJepang::progress($st->programStages))
                @php($stages = $st->programStages->keyBy('stage'))
                @php($current = collect(\App\Support\ProgramJepang::STAGES)->keys()->first(fn ($k) => ($stages->get($k)?->status ?? 'belum') !== 'selesai'))
                <tr>
                    <td><strong>{{ $st->name }}</strong>@if ($st->profile?->nis)<br><small>NIS {{ $st->profile->nis }}</small>@endif</td>
                    <td>{{ $st->classrooms->pluck('name')->implode(', ') ?: '–' }}</td>
                    <td>{{ $st->profile?->level?->code ?? '–' }}</td>
                    <td>{{ $current ? \App\Support\ProgramJepang::STAGES[$current] : 'Selesai' }}</td>
                    <td><div class="bar-track"><span style="width: {{ $progress }}%"></span></div><small>{{ $progress }}%</small></td>
                    <td class="actions"><a class="btn small secondary" href="{{ route('manage.program.show', $st) }}">Detail</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Tidak ada peserta.</td></tr>
            @endforelse
        </table></div>
    </div>
    {{ $students->links() }}
@endsection
