@extends('layouts.app')
@section('title', 'Import soal')
@section('container_class', 'narrow')
@section('content')
    <div class="page-head">
        <h1>Import soal dari Excel</h1>
        <a class="btn secondary" href="{{ route('sensei.questions.index') }}">Kembali</a>
    </div>

    <div class="card">
        <h2>1. Unduh template</h2>
        <p>Isi satu soal per baris. Template berisi contoh, petunjuk tiap kolom, dan daftar bagian yang tersedia.</p>
        <a class="btn secondary" href="{{ route('sensei.questions.import.template') }}">⬇ Unduh template (.xlsx)</a>
        <div class="alert info" style="margin-top:14px">
            <strong>Tips dari arsip lama:</strong> isi kolom <code>arsip_id</code> (nomor # di menu Arsip Lama) dan
            <code>no_arsip</code> (nomor soal di PDF), lalu kosongkan <code>jawaban</code> — kunci diambil otomatis dari arsip.
        </div>
    </div>

    <form method="POST" action="{{ route('sensei.questions.import.preview') }}" enctype="multipart/form-data" class="card">
        @csrf
        <h2>2. Upload file</h2>
        <div class="field">
            <label for="file">File .xlsx atau .csv (maks. 10 MB)</label>
            <input id="file" type="file" name="file" accept=".xlsx,.csv" required>
        </div>
        <p class="muted">Isi soal di sheet <strong>Soal</strong>. Bila memakai CSV, simpan sebagai <strong>CSV UTF-8</strong> supaya huruf Jepang tidak rusak.</p>
        <p class="muted">Belum ada yang disimpan di langkah ini. Anda akan melihat pratinjau dan hasil pengecekan setiap baris dulu.</p>
        <button class="btn">Periksa file</button>
    </form>
@endsection
