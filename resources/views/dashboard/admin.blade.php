@extends('layouts.app')
@section('title', 'Beranda Admin')
@section('content')
    <div class="page-head"><div><h1>Dashboard Admin</h1><p>Ringkasan aktivitas sistem ujian.</p></div></div>
    <div class="grid cols-3">
        <a class="stat" href="{{ route('admin.users.index', ['role' => 'student']) }}"><div class="ic">👥</div><div><p class="label">Peserta</p><p class="value">{{ $userCounts['student'] ?? 0 }}</p></div></a>
        <a class="stat" href="{{ route('admin.users.index', ['role' => 'test_taker']) }}"><div class="ic">🎫</div><div><p class="label">Peserta Tes</p><p class="value">{{ $userCounts['test_taker'] ?? 0 }}</p></div></a>
        <a class="stat" href="{{ route('admin.users.index', ['role' => 'sensei']) }}"><div class="ic purple">🧑‍🏫</div><div><p class="label">Instruktur (sensei)</p><p class="value">{{ $userCounts['sensei'] ?? 0 }}</p></div></a>
        <a class="stat" href="{{ route('sensei.exams.index') }}"><div class="ic green">📝</div><div><p class="label">Ujian dibuka</p><p class="value">{{ $openExams }}</p></div></a>
        <div class="stat"><div class="ic orange">⏱</div><div><p class="label">Ujian dikerjakan hari ini</p><p class="value">{{ $attemptsToday }}</p></div></div>
        <a class="stat" href="{{ route('sensei.questions.index', ['status' => 'published']) }}"><div class="ic">🗂️</div><div><p class="label">Soal siap pakai</p><p class="value">{{ $questionCount }}</p></div></a>
        <a class="stat" href="{{ route('admin.payments.index', ['status' => 'pending']) }}"><div class="ic pink">💳</div><div><p class="label">Pembayaran menunggu</p><p class="value">{{ $pendingPayments }}</p></div></a>
    </div>
    <div class="card" style="margin-top:16px">
        <h2>Pintasan</h2>
        <p>
            <a class="btn" href="{{ route('admin.users.create') }}">+ Tambah user</a>
            <a class="btn secondary" href="{{ route('admin.payments.index') }}">Catat pembayaran</a>
            <a class="btn secondary" href="{{ route('sensei.exams.index') }}">Lihat semua ujian</a>
        </p>
    </div>
@endsection
