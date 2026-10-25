<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Akses ditolak · {{ config('app.brand') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <style>
        :root { --bg: #f6f3ee; --card: #fff; --ink: #1c2434; --muted: #6b7280; --line: #e5e1d8; --navy: #1c3765; --warn-bg: #fff6e5; --warn-line: #f1d39a; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--ink); font: 15px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
        .card { width: 100%; max-width: 480px; background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 28px; }
        .code { font-size: 13px; font-weight: 700; letter-spacing: .08em; color: var(--muted); }
        h1 { margin: 4px 0 8px; font-size: 22px; }
        p { margin: 0 0 12px; color: var(--muted); }
        .who { background: var(--warn-bg); border: 1px solid var(--warn-line); border-radius: 10px; padding: 12px 14px; margin: 16px 0; color: var(--ink); }
        .who strong { display: block; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }
        .btn { appearance: none; border: 1px solid var(--line); background: #fff; color: var(--ink); border-radius: 10px; padding: 10px 16px; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn.primary { background: var(--navy); border-color: var(--navy); color: #fff; }
    </style>
</head>
<body>
@php($user = auth()->user())
<main class="card">
    <div class="code">403</div>
    <h1>Anda tidak punya akses ke halaman ini.</h1>

    @if ($user)
        <div class="who">
            Browser ini sedang login sebagai
            <strong>{{ $user->name }} · {{ $user->role?->label() ?? '-' }}</strong>
        </div>
        <p>
            Jika Anda sedang mengerjakan ujian, kemungkinan akun di browser ini berganti
            (misalnya login akun lain di tab lain). Jawaban yang sudah tersimpan tidak hilang.
            Keluar, lalu login kembali dengan akun peserta ujian untuk melanjutkan.
        </p>
        <div class="actions">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn primary">Keluar &amp; login ulang</button>
            </form>
            <a class="btn" href="{{ route('dashboard') }}">Kembali ke beranda</a>
        </div>
    @else
        <p>Sesi Anda sudah berakhir. Silakan login kembali.</p>
        <div class="actions">
            <a class="btn primary" href="{{ route('login') }}">Login</a>
        </div>
    @endif
</main>
</body>
</html>
