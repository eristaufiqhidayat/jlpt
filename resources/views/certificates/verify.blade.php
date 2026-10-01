<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verifikasi Sertifikat · {{ config('app.brand') }}</title>
    <link rel="icon" href="{{ asset('favicon.png') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=4">
</head>
<body style="background:var(--bg)">
    <div class="container" style="max-width:900px;padding:24px 16px">
        @if ($certificate)
            <div class="alert success no-print">✓ Sertifikat <strong>{{ $certificate->number }}</strong> terdaftar dan sah, diterbitkan oleh {{ config('app.brand') }}.</div>
            @include('certificates._cert')
        @else
            <div class="alert error">Sertifikat tidak ditemukan. Periksa kembali tautan verifikasi.</div>
        @endif
    </div>
</body>
</html>
