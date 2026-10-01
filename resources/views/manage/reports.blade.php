@extends('layouts.app')
@section('title', 'Laporan & Analitik')
@section('content')
    @php($colors = ['N5' => 'var(--blue)', 'N4' => 'var(--green)', 'N3' => 'var(--orange)', 'N2' => 'var(--purple)', 'N1' => 'var(--sakura-deep)'])
    <div class="page-head no-print">
        <div><h1>Laporan & Analitik</h1><p>Ringkasan peserta, kelulusan, dan Program Jepang.</p></div>
        <button type="button" class="btn secondary js-only" onclick="window.print()">🖨 Cetak</button>
    </div>
    <form method="GET" class="filters no-print">
        <div class="field"><label for="year">Tahun</label>
            <select id="year" name="year">@foreach (range(now()->year, now()->year - 5) as $y)<option @selected($year === $y)>{{ $y }}</option>@endforeach</select></div>
        <div class="field"><label for="level_id">Level</label>
            <select id="level_id" name="level_id"><option value="">Semua</option>
                @foreach ($levels->filter(fn ($l) => $l->testType->code === 'JLPT') as $lv)<option value="{{ $lv->id }}" @selected($levelId === $lv->id)>{{ $lv->code }}</option>@endforeach
            </select></div>
        <div class="field"><label for="classroom_id">Kelas</label>
            <select id="classroom_id" name="classroom_id"><option value="">Semua</option>
                @foreach ($classrooms as $c)<option value="{{ $c->id }}" @selected($classId === $c->id)>{{ $c->name }}</option>@endforeach
            </select></div>
        <button class="btn">Tampilkan</button>
    </form>

    <div class="grid cols-4" style="margin-bottom:16px">
        <div class="stat"><div class="ic">👥</div><div><p class="label">Peserta aktif</p><p class="value">{{ $stats['students'] }}</p></div></div>
        <div class="stat"><div class="ic orange">🏫</div><div><p class="label">Kelas aktif</p><p class="value">{{ $stats['classes'] }}</p></div></div>
        <div class="stat"><div class="ic green">🏅</div><div><p class="label">Lulus {{ $year }}</p><p class="value">{{ $stats['passed'] }}</p></div></div>
        <div class="stat"><div class="ic purple">✈️</div><div><p class="label">Siap / sudah berangkat</p><p class="value">{{ $stats['ready'] }}</p></div></div>
    </div>

    <div class="grid cols-2" style="align-items:start">
        <div class="card">
            <div class="card-head"><h2>Peserta per level</h2></div>
            @php($max = max(1, $perLevel->max() ?? 0))
            <div class="chart-bars">
                @foreach ($perLevel as $code => $n)
                    <div class="col"><span class="v">{{ $n }}</span><div class="b" style="height: {{ round($n / $max * 140) }}px; background: {{ $colors[$code] ?? 'var(--navy-600)' }}"></div><span class="l">{{ $code }}</span></div>
                @endforeach
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h2>Tren kelulusan (sertifikat)</h2></div>
            @if ($trend->isEmpty())
                <p class="muted">Belum ada sertifikat pada {{ $years[0] }}–{{ end($years) }}.</p>
            @else
                @php($tmax = max(1, $trend->flatten()->max()))
                <div class="chart-bars">
                    @foreach ($years as $i => $y)
                        <div class="col">
                            <div style="display:flex;align-items:flex-end;gap:4px;height:150px;width:100%;justify-content:center">
                                @foreach ($trend as $code => $vals)
                                    <div class="b" title="{{ $code }} {{ $y }}: {{ $vals[$i] }}" style="width:14px;height: {{ round($vals[$i] / $tmax * 140) }}px; background: {{ $colors[$code] ?? 'var(--navy-600)' }}"></div>
                                @endforeach
                            </div>
                            <span class="l">{{ $y }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="legend-row">@foreach ($trend as $code => $vals)<span><i style="background: {{ $colors[$code] ?? 'var(--navy-600)' }}"></i>{{ $code }} ({{ array_sum($vals) }})</span>@endforeach</div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Tahapan Program Jepang</h2><a class="no-print" href="{{ route('manage.program.index') }}">Detail peserta</a></div>
        @php($smax = max(1, $stats['students']))
        @foreach ($stagesSummary as $s)
            <div class="kompetensi-row">
                <div class="lbl">{{ $s['label'] }}<small>{{ $s['done'] }} selesai · {{ $s['process'] }} proses</small></div>
                <div class="kbar"><span style="width: {{ round($s['done'] / $smax * 100) }}%; background: var(--green)"></span></div>
                <div class="val">{{ round($s['done'] / $smax * 100) }}%</div>
            </div>
        @endforeach
    </div>
@endsection
