@extends('layouts.app')
@section('title', ($model->exists ? 'Ubah ' : 'Tambah ').$config['title'])
@section('container_class', 'narrow')
@section('content')
    @php $back = route('manage.master.index', ['tab' => $config['tab']]); @endphp
    <div class="page-head">
        <h1>{{ ($model->exists ? 'Ubah ' : 'Tambah ').$config['title'] }}@if ($model->exists): {{ $model->masterLabel() }}@endif</h1>
        <a class="btn secondary" href="{{ $back }}">Kembali</a>
    </div>
    <div class="alert info">{{ $config['intro'] }}</div>
    <form method="POST" class="card" action="{{ $model->exists ? route('manage.master.update', [$entity, $model->id]) : route('manage.master.store', $entity) }}">
        @csrf @if ($model->exists) @method('PUT') @endif
        @if (request('from') === 'skor')<input type="hidden" name="from" value="skor">@endif

        @foreach ($config['fields'] as $name => $field)
            @php
                $value = old($name, $model->{$name} instanceof \DateTimeInterface ? $model->{$name}->format('Y-m-d') : $model->{$name});
                $required = in_array('required', $field['rules'], true) && ! ($field['readonly'] ?? false);
            @endphp
            <div class="field">
                <label for="{{ $name }}">{{ $field['label'] }}@if ($required) <span aria-hidden="true">*</span>@endif</label>
                @if ($field['type'] === 'select')
                    <select id="{{ $name }}" name="{{ $name }}" @required($required) @disabled($field['readonly'] ?? false)>
                        <option value="">– pilih –</option>
                        @foreach ($field['options'] as $key => $label)
                            @if (is_array($label))
                                <optgroup label="{{ $key }}">
                                    @foreach ($label as $k => $l)<option value="{{ $k }}" @selected((string) $value === (string) $k)>{{ $l }}</option>@endforeach
                                </optgroup>
                            @else
                                <option value="{{ $key }}" @selected((string) $value === (string) $key)>{{ $label }}</option>
                            @endif
                        @endforeach
                    </select>
                @else
                    <input id="{{ $name }}" name="{{ $name }}" type="{{ $field['type'] }}" value="{{ $value }}"
                           @required($required) @if ($field['readonly'] ?? false) readonly disabled @endif
                           @if ($field['type'] === 'number') inputmode="numeric" @endif
                           @isset($field['list']) list="{{ $name }}-list" @endisset>
                    @isset($field['list'])
                        <datalist id="{{ $name }}-list">@foreach ($field['list'] as $opt)<option value="{{ $opt }}">@endforeach</datalist>
                    @endisset
                @endif
                @isset($field['hint'])<div class="hint">{{ $field['hint'] }}</div>@endisset
                @error($name)<div class="error-text">{{ $message }}</div>@enderror
            </div>
        @endforeach

        <div class="btnrow">
            <button class="btn">Simpan</button>
            <a class="btn secondary" href="{{ $back }}">Batal</a>
        </div>
        @if ($model->exists)
            <p class="muted" style="margin:12px 0 0;font-size:12px">Perubahan dicatat di log Data Master.</p>
        @endif
    </form>
@endsection
