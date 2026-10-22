<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $testPortal ? 'Tes Online JLPT' : 'Masuk' }} · {{ config('app.brand') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=13">
</head>
<body @class(['portal-test' => $testPortal])>
<div class="login-wrap">
    <div class="login-visual">
        <div class="fuji" aria-hidden="true"></div>
        <div>
            <div class="brand-mark">
                <img src="{{ asset('images/logo-160.png') }}" alt="Logo {{ config('app.brand') }}">
                <div>
                    <h1>{{ config('app.brand') }}</h1>
                    <p>{{ config('app.brand_tagline') }}</p>
                </div>
            </div>
            <div class="login-tagline">
                @if ($testPortal)
                    <p class="jp" lang="ja">日本語能力試験<br>オンライン模試</p>
                    <p class="en">Tes Online JLPT · J-Test · NAT-Test</p>
                @else
                    <p class="jp" lang="ja">日本へ、<br>ともに未来を</p>
                    <p class="en">Together to a Brighter Future</p>
                @endif
            </div>
        </div>
        <p class="login-quote">{{ $testPortal ? 'Ukur kemampuan bahasa Jepang Anda dengan simulasi ujian resmi' : '"Bersama Menggapai Masa Depan di Jepang"' }}</p>
    </div>

    <div class="login-form-side">
        <div class="login-card">
            <div class="mobile-brand">
                <img src="{{ asset('images/logo-160.png') }}" alt="">
                <strong>{{ config('app.brand') }}</strong>
            </div>
            @if ($testPortal)
                <span class="portal-chip">Tes Online</span>
                <h2>Tes Online JLPT – Jakaruta Global Group</h2>
                <p class="sub">Untuk peserta tes (umum/berbayar). Siswa LPK masuk lewat LMS.</p>
            @else
                <h2>LMS LPK Jakaruta Global Group</h2>
                <p class="sub">Untuk siswa, sensei, dan staf.</p>
            @endif

            @if (session('wrong_portal'))
                <div class="alert info portal-notice">
                    {{ session('wrong_portal')['text'] }}
                    <a class="btn small" href="{{ session('wrong_portal')['url'] }}">{{ session('wrong_portal')['label'] }} →</a>
                </div>
            @endif

            @if ($errors->any())
                <div class="alert error">{{ $errors->first() }}</div>
            @endif
            @if (session('success'))
                <div class="alert success">{{ session('success') }}</div>
            @endif

            <form method="POST" action="{{ route('login') }}">
                @csrf
                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" placeholder="nama@email.com" required autofocus autocomplete="username">
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <div class="field-pw">
                        <input id="password" type="password" name="password" required autocomplete="current-password">
                        <button type="button" aria-label="Tampilkan password"
                                onclick="const i=document.getElementById('password');i.type=i.type==='password'?'text':'password'">👁</button>
                    </div>
                </div>
                <div class="field">
                    <label class="check"><input type="checkbox" name="remember" value="1"> Ingat saya</label>
                </div>
                <button type="submit" class="btn big block">Masuk</button>
                <p class="muted" style="font-size:12px;text-align:center;margin-top:18px">Lupa password? Hubungi admin lembaga.</p>
            </form>

            @if ($demoRoles->isNotEmpty())
                <div class="demo-login">
                    <p><strong>Demo masuk cepat</strong> · mode uji coba, tanpa password</p>
                    <div class="demo-login-btns">
                        @foreach ($demoRoles as $role)
                            <form method="POST" action="{{ route('login.demo', $role->value) }}">
                                @csrf
                                <button type="submit" class="btn small secondary">{{ $role->label() }}</button>
                            </form>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
</body>
</html>
