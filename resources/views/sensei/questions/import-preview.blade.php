@extends('layouts.app')
@section('title', 'Pratinjau import')
@section('content')
    <div class="page-head">
        <div>
            <h1>Pratinjau import</h1>
            <span class="muted">{{ $filename }} · {{ count($rows) }} baris</span>
        </div>
        <a class="btn secondary" href="{{ route('sensei.questions.import') }}">Upload file lain</a>
    </div>

    <div class="grid cols-3">
        <div class="stat"><div class="value" style="color:var(--ok)">{{ $valid }}</div><div class="label">Siap diimport</div></div>
        <div class="stat"><div class="value" style="color:var(--err)">{{ $invalid }}</div><div class="label">Ada kesalahan (dilewati)</div></div>
        <div class="stat"><div class="value" style="color:var(--warn)">{{ $warnings }}</div><div class="label">Valid dengan catatan</div></div>
    </div>

    <div class="card" style="margin-top:16px">
        @if ($valid > 0)
            <form method="POST" action="{{ route('sensei.questions.import.store') }}" class="inline-form">
                @csrf <input type="hidden" name="token" value="{{ $token }}">
                <button class="btn success big">Import {{ $valid }} soal</button>
                @if ($invalid)<span class="muted">{{ $invalid }} baris yang salah tidak ikut diimport. Perbaiki di Excel lalu upload lagi baris-baris itu.</span>@endif
            </form>
        @else
            <div class="alert error" style="margin:0">Tidak ada baris yang valid. Perbaiki file sesuai catatan di bawah, lalu upload lagi.</div>
        @endif
    </div>

    <div class="card" style="padding:0">
        <div class="table-wrap"><table>
            <tr><th>Baris</th><th>Bagian</th><th>Soal</th><th>Pilihan</th><th>Kunci</th><th>Hasil cek</th></tr>
            @foreach ($rows as $row)
                @php($d = $row['data'])
                <tr @if ($row['errors']) style="background:var(--err-bg)" @endif>
                    <td>{{ $row['line'] }}</td>
                    <td><small>{{ $row['section_label'] ?? '–' }}</small><br><small class="muted">{{ \App\Http\Controllers\Sensei\QuestionController::TYPE_LABELS[$d['type']] ?? $d['type'] }}</small></td>
                    <td lang="ja" style="max-width:360px">@jp($d['stem'])</td>
                    <td lang="ja"><small>@foreach ($d['options'] as $n => $o){{ $n }}. {{ $o }}<br>@endforeach</small></td>
                    <td>{{ $d['type'] === 'essay' ? 'esai' : ($d['correct'] ?? '–') }}</td>
                    <td>
                        @foreach ($row['errors'] as $e)<div class="error-text">✗ {{ $e }}</div>@endforeach
                        @foreach ($row['warnings'] as $w)<div style="color:var(--warn);font-size:.85rem">! {{ $w }}</div>@endforeach
                        @if (! $row['errors'] && ! $row['warnings'])<span class="badge ok">OK</span>@endif
                    </td>
                </tr>
            @endforeach
        </table></div>
    </div>
@endsection
