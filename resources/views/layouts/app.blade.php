<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.brand') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=13">
    @stack('head')
</head>
<body>
@php
    $user = auth()->user();
    $role = $user->role->value;
    $initials = $user->initials();
    $roleLabel = ['admin' => 'Administrator', 'education_admin' => 'Admin Pendidikan', 'sensei' => 'Instruktur', 'student' => 'Peserta', 'director' => 'Direktur', 'test_taker' => 'Peserta Tes', 'proctor' => 'Pengawas Ujian'][$role] ?? $user->role->label();
    $subLabel = $role === 'student' && $user->profile?->level ? 'Peserta · '.$user->profile->level->code : $roleLabel;
    $unread = $user->inbox()->whereNull('read_at')->count();
    $item = fn ($label, $icon, $route, $patterns, $count = 0) => [$label, $icon, route($route), request()->routeIs(...(array) $patterns), $count];
    $groups = [];
    if ($role === 'test_taker') {
        // Peserta tes umum (domain tes online): hanya ujian, nilai, sertifikat.
        $groups[''] = [
            $item('Tes Saya', '📝', 'student.exams', ['student.exams', 'student.attempts.show', 'student.payments.*']),
            $item('Hasil Tes', '📊', 'student.results', ['student.results', 'student.attempts.result', 'student.attempts.review']),
            $item('Sertifikat', '🏅', 'student.certificates', ['student.certificates', 'certificates.show']),
            $item('Profil', '👤', 'profile.edit', 'profile.*'),
        ];
    } elseif ($role === 'proctor') {
        // Pengawas: hanya memantau ujian yang sedang berjalan.
        $groups[''] = [
            $item('Pengawasan Ujian', '👁️', 'proctor.index', 'proctor.*'),
            $item('Pesan', '✉️', 'messages.index', 'messages.*', $unread),
            $item('Profil', '👤', 'profile.edit', 'profile.*'),
        ];
    } elseif ($role === 'student') {
        $groups[''] = [
            $item('Dashboard', '🏠', 'dashboard', 'dashboard'),
            $item('Materi', '📘', 'student.materials', 'student.materials*'),
            $item('Kelas Saya', '🏫', 'student.classes', 'student.classes'),
            $item('Ujian', '📝', 'student.exams', ['student.exams', 'student.attempts.show', 'student.payments.*']),
            $item('Nilai', '📊', 'student.results', ['student.results', 'student.attempts.result', 'student.attempts.review']),
            $item('Sertifikat', '🏅', 'student.certificates', ['student.certificates', 'certificates.show']),
            $item('Program Jepang', '✈️', 'student.program', 'student.program'),
            $item('Pesan', '✉️', 'messages.index', 'messages.*', $unread),
            $item('Profil', '👤', 'profile.edit', 'profile.*'),
        ];
    } elseif ($role === 'director') {
        $groups[''] = [
            $item('Laporan', '📈', 'reports', 'reports'),
            $item('Data Master', '🗃️', 'manage.master.index', 'manage.master.*'),
            $item('Program Jepang', '✈️', 'manage.program.index', 'manage.program.*'),
            $item('Pesan', '✉️', 'messages.index', 'messages.*', $unread),
            $item('Profil', '👤', 'profile.edit', 'profile.*'),
        ];
    } else {
        $groups[''] = $role === 'education_admin'
            ? [$item('Data Master', '🗃️', 'manage.master.index', 'manage.master.*')]
            : [$item('Dashboard', '🏠', 'dashboard', 'dashboard')];
        $groups['Pembelajaran'] = [
            $item('Materi', '📘', 'manage.materials.index', 'manage.materials.*'),
            $item('Kelas & Jadwal', '🏫', 'manage.classes.index', ['manage.classes.*', 'manage.sessions.*']),
            $item('Program Jepang', '✈️', 'manage.program.index', 'manage.program.*'),
        ];
        if ($role !== 'education_admin') {
            // Admin Pendidikan sudah punya Data Master di urutan pertama
            array_unshift($groups['Pembelajaran'], $item('Data Master', '🗃️', 'manage.master.index', 'manage.master.*'));
        }
        $groups['Ujian'] = [
            $item('Pelatihan Soal', '🗂️', 'sensei.questions.index', 'sensei.questions.*'),
            $item('Bacaan & Audio', '🎧', 'sensei.passages.index', 'sensei.passages.*'),
            $item('Ujian', '📝', 'sensei.exams.index', 'sensei.exams.*'),
            $item('Pengawasan', '👁️', 'proctor.index', 'proctor.*'),
            $item('Penilaian Esai', '✍️', 'sensei.grading.index', 'sensei.grading.*'),
            $item('Arsip Lama', '📦', 'sensei.legacy.index', 'sensei.legacy.*'),
        ];
        if ($role === 'education_admin') {
            $groups['Administrasi'] = [$item('Laporan', '📈', 'reports', 'reports')];
        }
        if ($role === 'admin') {
            $groups['Administrasi'] = [
                $item('User', '👥', 'admin.users.index', 'admin.users.*'),
                $item('Pembayaran', '💳', 'admin.payments.index', 'admin.payments.*'),
                $item('Laporan', '📈', 'reports', 'reports'),
                $item('Backup', '💾', 'admin.backup.index', 'admin.backup.*'),
            ];
        }
        $groups['Akun'] = [
            $item('Pesan', '✉️', 'messages.index', 'messages.*', $unread),
            $item('Profil', '👤', 'profile.edit', 'profile.*'),
        ];
    }
@endphp
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a href="{{ route('dashboard') }}" class="sb-brand">
            <img src="{{ asset('images/logo-160.png') }}" alt="Logo {{ config('app.brand') }}">
            <div><p>{{ config('app.brand') }}</p><span>{{ $subLabel }}</span></div>
        </a>
        <nav class="sb-nav" aria-label="Menu utama">
            @foreach ($groups as $groupLabel => $items)
                @if ($groupLabel)<div class="sb-label">{{ $groupLabel }}</div>@endif
                @foreach ($items as [$label, $icon, $url, $active, $count])
                    <a href="{{ $url }}" @class(['sb-item', 'active' => $active])><span class="ic">{{ $icon }}</span>{{ $label }}@if ($count)<span class="count">{{ $count }}</span>@endif</a>
                @endforeach
            @endforeach
        </nav>
        <div class="sb-foot">
            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">⏻ Keluar</button></form>
        </div>
    </aside>
    <div class="sidebar-backdrop" onclick="document.body.classList.remove('nav-open')"></div>

    <div class="main">
        <header class="topbar">
            <div class="crumb">
                <button type="button" class="menu-toggle" aria-label="Buka menu" onclick="document.body.classList.toggle('nav-open')">☰</button>
                @hasSection('crumb') @yield('crumb') @else <b>@yield('title', 'Dashboard')</b> @endif
            </div>
            <div class="topbar-right">
                <a href="{{ route('messages.index') }}" class="bell" aria-label="Pesan{{ $unread ? ", $unread belum dibaca" : '' }}">🔔@if ($unread)<span class="dot"></span>@endif</a>
                <a href="{{ route('profile.edit') }}" class="user-chip" style="text-decoration:none;color:inherit">
                    <div class="avatar" aria-hidden="true">{{ $initials }}</div>
                    <div class="txt"><p>{{ $user->name }}</p><span>{{ $subLabel }}</span></div>
                </a>
            </div>
        </header>
        <main class="container @yield('container_class')">
            @include('partials.flash')
            @yield('content')
        </main>
    </div>
</div>
@stack('scripts')
</body>
</html>
