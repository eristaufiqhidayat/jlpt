@extends('layouts.app')
@section('title', 'Tulis pesan')
@section('container_class', 'narrow')
@section('content')
    <div class="page-head"><h1>Tulis pesan</h1><a class="btn secondary" href="{{ route('messages.index') }}">Kembali</a></div>
    <form method="POST" action="{{ route('messages.store') }}" class="card">
        @csrf
        <div class="field">
            <label>Kirim ke</label>
            <label class="check"><input type="radio" name="target" value="student" @checked(old('target', 'student') === 'student')> Satu peserta</label>
            <label class="check"><input type="radio" name="target" value="classroom" @checked(old('target') === 'classroom')> Semua peserta di satu kelas</label>
        </div>
        <div class="row">
            <div class="field">
                <label for="recipient_id">Peserta</label>
                <select id="recipient_id" name="recipient_id">
                    <option value="">– pilih –</option>
                    @foreach ($students as $s)<option value="{{ $s->id }}" @selected((int) old('recipient_id', request('to')) === $s->id)>{{ $s->name }} ({{ $s->email }})</option>@endforeach
                </select>
                @error('recipient_id')<div class="error-text">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="classroom_id">Kelas</label>
                <select id="classroom_id" name="classroom_id">
                    <option value="">– pilih –</option>
                    @foreach ($classrooms as $c)<option value="{{ $c->id }}" @selected((int) old('classroom_id') === $c->id)>{{ $c->name }} ({{ $c->students_count }} peserta)</option>@endforeach
                </select>
            </div>
        </div>
        <div class="field">
            <label for="subject">Judul</label>
            <input id="subject" name="subject" type="text" maxlength="150" value="{{ old('subject') }}" required>
        </div>
        <div class="field">
            <label for="body">Isi pesan</label>
            <textarea id="body" name="body" rows="6" required>{{ old('body') }}</textarea>
        </div>
        <button class="btn">Kirim</button>
    </form>
@endsection
