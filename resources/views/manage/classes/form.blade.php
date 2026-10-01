@extends('layouts.app')
@section('title', $class->exists ? 'Edit kelas' : 'Tambah kelas')
@section('container_class', 'narrow')
@section('content')
    <div class="page-head"><h1>{{ $class->exists ? 'Edit kelas' : 'Tambah kelas' }}</h1><a class="btn secondary" href="{{ $class->exists ? route('manage.classes.show', $class) : route('manage.classes.index') }}">Kembali</a></div>
    <form method="POST" class="card" action="{{ $class->exists ? route('manage.classes.update', $class) : route('manage.classes.store') }}">
        @csrf @if ($class->exists) @method('PUT') @endif
        <div class="field"><label for="name">Nama kelas</label><input id="name" name="name" type="text" maxlength="60" value="{{ old('name', $class->name) }}" required placeholder="mis. N4-A"></div>
        <div class="row">
            <div class="field"><label for="level_id">Level</label>
                <select id="level_id" name="level_id"><option value="">–</option>
                    @foreach ($levels as $lv)<option value="{{ $lv->id }}" @selected((int) old('level_id', $class->level_id) === $lv->id)>{{ $lv->testType->code }} {{ $lv->code }}</option>@endforeach
                </select></div>
            <div class="field"><label for="sensei_id">Instruktur</label>
                <select id="sensei_id" name="sensei_id"><option value="">–</option>
                    @foreach ($senseis as $s)<option value="{{ $s->id }}" @selected((int) old('sensei_id', $class->sensei_id) === $s->id)>{{ $s->name }}</option>@endforeach
                </select></div>
        </div>
        <div class="field"><label for="description">Keterangan</label><textarea id="description" name="description" rows="3">{{ old('description', $class->description) }}</textarea></div>
        <div class="field"><label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $class->is_active))> Kelas aktif</label></div>
        <button class="btn">Simpan</button>
    </form>
@endsection
