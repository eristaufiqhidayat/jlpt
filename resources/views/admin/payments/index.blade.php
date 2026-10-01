@extends('layouts.app')
@section('title', 'Pembayaran')
@section('content')
    <div class="page-head"><h1>Pembayaran</h1></div>

    <div class="card">
        <h2>Catat pembayaran manual</h2>
        <p class="muted">Untuk transfer bank yang sudah dicek. Setelah dicatat, student langsung bisa mengikuti ujian berbayar tersebut.</p>
        @if ($exams->isEmpty())
            <div class="alert info">Belum ada ujian berbayar.</div>
        @else
            <form method="POST" action="{{ route('admin.payments.store') }}" class="inline-form">
                @csrf
                <div class="field" style="min-width:220px;flex:1">
                    <label for="student_id">Student</label>
                    <select id="student_id" name="student_id" required>
                        <option value="">— pilih —</option>
                        @foreach ($students as $s)
                            <option value="{{ $s->id }}" @selected(old('student_id') == $s->id)>{{ $s->name }} ({{ $s->email }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="min-width:220px;flex:1">
                    <label for="exam_id">Ujian</label>
                    <select id="exam_id" name="exam_id" required onchange="document.getElementById('amount').value=this.selectedOptions[0].dataset.price||''">
                        <option value="">— pilih —</option>
                        @foreach ($exams as $e)
                            <option value="{{ $e->id }}" data-price="{{ $e->price }}" @selected(old('exam_id') == $e->id)>{{ $e->title }} [{{ $e->code }}] — Rp{{ number_format($e->price, 0, ',', '.') }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="width:140px">
                    <label for="amount">Jumlah (Rp)</label>
                    <input id="amount" name="amount" type="number" min="0" value="{{ old('amount') }}" required>
                </div>
                <div class="field" style="width:180px">
                    <label for="gateway_ref">No. referensi</label>
                    <input id="gateway_ref" name="gateway_ref" type="text" value="{{ old('gateway_ref') }}" placeholder="opsional">
                </div>
                <button class="btn">Catat lunas</button>
            </form>
        @endif
    </div>

    <form method="GET" class="filters">
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status" onchange="this.form.submit()">
                <option value="">Semua</option>
                @foreach ($statuses as $st)
                    <option value="{{ $st->value }}" @selected(request('status') === $st->value)>{{ $st->value }}</option>
                @endforeach
            </select>
        </div>
    </form>

    <div class="card" style="padding:0">
        <div class="table-wrap"><table>
            <tr><th>Tanggal</th><th>Student</th><th>Ujian</th><th class="num">Jumlah</th><th>Metode</th><th>Status</th><th></th></tr>
            @forelse ($payments as $p)
                <tr>
                    <td>{{ $p->created_at->format('d/m/Y H:i') }}<br><small>{{ $p->order_id }}</small></td>
                    <td>{{ $p->student->name }}</td>
                    <td>{{ $p->exam->title }}</td>
                    <td class="num">Rp{{ number_format($p->amount, 0, ',', '.') }}</td>
                    <td>{{ $p->gateway }}@if ($p->gateway_ref)<br><small>{{ $p->gateway_ref }}</small>@endif</td>
                    <td><span class="badge {{ $p->status->value === 'paid' ? 'ok' : ($p->status->value === 'pending' ? 'warn' : 'err') }}">{{ $p->status->value }}</span></td>
                    <td class="actions">
                        <form method="POST" action="{{ route('admin.payments.status', $p) }}" class="inline-form" style="justify-content:flex-end">
                            @csrf
                            <select name="status" style="width:auto">
                                @foreach ($statuses as $st)
                                    <option value="{{ $st->value }}" @selected($p->status === $st)>{{ $st->value }}</option>
                                @endforeach
                            </select>
                            <button class="btn small secondary">Ubah</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Belum ada pembayaran.</td></tr>
            @endforelse
        </table></div>
    </div>
    {{ $payments->links() }}
@endsection
