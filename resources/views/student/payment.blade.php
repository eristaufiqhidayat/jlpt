@extends('layouts.app')
@section('title', 'Pembayaran ujian')
@section('container_class', 'narrow')
@section('content')
    <div class="card">
        <h1>{{ $exam->title }}</h1>
        <p class="muted">{{ $exam->level->testType->name }} {{ $exam->level->code }} · {{ $exam->sections->count() }} bagian · {{ $exam->totalDurationMinutes() }} menit</p>
        @if ($exam->description)<p>{{ $exam->description }}</p>@endif
        <div class="score-big" style="font-size:2.2rem;margin:12px 0">Rp{{ number_format($exam->price, 0, ',', '.') }}</div>

        @if ($paid)
            <div class="alert success">Pembayaran sudah diterima. Anda bisa mengikuti ujian ini.</div>
            <form method="POST" action="{{ route('student.join') }}">
                @csrf <input type="hidden" name="code" value="{{ $exam->code }}">
                <button class="btn big success">Mulai ujian</button>
            </form>
        @elseif (! $online)
            <div class="alert info">Pembayaran online belum aktif. Silakan transfer dan konfirmasi ke admin; setelah dicatat, ujian bisa langsung dimulai.</div>
        @else
            @if ($pending)
                <div class="alert warn">
                    Ada pembayaran yang belum selesai (order {{ $pending->order_id }}, berlaku sampai {{ $pending->expires_at?->format('d/m/Y H:i') }}).
                    Tekan "Bayar sekarang" untuk melihat instruksinya lagi.
                </div>
            @endif
            <p class="muted">Bisa dibayar dengan QRIS, transfer virtual account, e-wallet, atau metode lain yang tersedia di Midtrans.</p>
            <p>
                <button type="button" class="btn big" id="pay-btn">Bayar sekarang</button>
                @if ($pending)
                    <form method="POST" action="{{ route('student.payments.refresh', $exam->code) }}" style="display:inline">
                        @csrf <button class="btn secondary big">Cek status</button>
                    </form>
                @endif
            </p>
            <noscript>
                <form method="POST" action="{{ route('student.payments.pay', $exam->code) }}">@csrf<button class="btn big">Bayar (halaman Midtrans)</button></form>
            </noscript>
            <p id="pay-msg" class="error-text"></p>
            <form method="POST" action="{{ route('student.payments.refresh', $exam->code) }}" id="refresh-form">@csrf</form>
        @endif
    </div>
    <p><a class="btn secondary" href="{{ route('dashboard') }}">Kembali ke beranda</a></p>
@endsection

@if (! $paid && $online)
@push('scripts')
<script src="{{ $snapJs }}" data-client-key="{{ $clientKey }}"></script>
<script>
(function () {
    const btn = document.getElementById('pay-btn'), msg = document.getElementById('pay-msg');
    const refresh = () => document.getElementById('refresh-form').submit();
    btn.addEventListener('click', async () => {
        btn.disabled = true; msg.textContent = '';
        try {
            const res = await fetch(@json(route('student.payments.pay', $exam->code)), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
            });
            const data = await res.json();
            if (data.paid) return location.reload();
            if (!res.ok || !data.token) throw new Error(data.message || 'Gagal membuat pembayaran.');
            if (!window.snap) throw new Error('Halaman pembayaran Midtrans tidak bisa dimuat. Periksa koneksi internet.');
            window.snap.pay(data.token, {
                onSuccess: refresh,
                onPending: refresh,
                onError: () => { msg.textContent = 'Pembayaran gagal. Silakan coba lagi.'; btn.disabled = false; },
                onClose: refresh,
            });
        } catch (e) {
            msg.textContent = e.message; btn.disabled = false;
        }
    });
})();
</script>
@endpush
@endif
