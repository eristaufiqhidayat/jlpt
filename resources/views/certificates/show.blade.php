@extends('layouts.app')
@section('title', 'Sertifikat '.$certificate->number)
@section('content')
    @php($verifyUrl = route('certificates.verify', $certificate->verify_token))
    <div class="page-head no-print">
        <div><h1>Sertifikat Digital</h1><p>{{ $certificate->number }}</p></div>
        <div class="btnrow" style="flex-wrap:wrap">
            <button type="button" class="btn" onclick="window.print()">⬇ Download / Cetak PDF</button>
            <a class="btn secondary" href="{{ $verifyUrl }}" target="_blank">🔍 Verifikasi</a>
            <button type="button" class="btn secondary js-only" id="share-btn" data-url="{{ $verifyUrl }}">🔗 Bagikan</button>
        </div>
    </div>
    @include('certificates._cert')
    <p class="muted no-print" style="text-align:center;font-size:12.5px">Pilih "Save as PDF" pada jendela cetak untuk menyimpan sertifikat. Tautan verifikasi bisa dibagikan ke perusahaan/lembaga.</p>
    <script>
        document.getElementById('share-btn')?.addEventListener('click', async (e) => {
            const url = e.currentTarget.dataset.url;
            try {
                if (navigator.share) { await navigator.share({ title: 'Sertifikat', url }); return; }
                await navigator.clipboard.writeText(url);
                e.currentTarget.textContent = '✓ Tautan disalin';
            } catch (_) { window.prompt('Salin tautan verifikasi:', url); }
        });
    </script>
@endsection
