@extends('layouts.app')
@section('title', $message->subject)
@section('container_class', 'narrow')
@section('content')
    <div class="page-head"><h1>{{ $message->subject }}</h1><a class="btn secondary" href="{{ route('messages.index') }}">Kembali</a></div>
    <div class="card">
        <p class="muted" style="font-size:12.5px;margin-top:0">Dari <strong>{{ $message->sender?->name ?? 'Sistem' }}</strong> kepada <strong>{{ $message->recipient->name }}</strong> · {{ $message->created_at->translatedFormat('d F Y H:i') }}</p>
        <div style="white-space:pre-line;line-height:1.7">{{ $message->body }}</div>
    </div>
    @if ($message->recipient_id === auth()->id() && $message->sender_id)
        <form method="POST" action="{{ route('messages.store') }}" class="card">
            @csrf
            <input type="hidden" name="target" value="reply">
            <input type="hidden" name="reply_to" value="{{ $message->id }}">
            <input type="hidden" name="subject" value="Re: {{ \Illuminate\Support\Str::limit(preg_replace('/^(Re: )+/', '', $message->subject), 140, '') }}">
            <div class="field">
                <label for="body">Balas ke {{ $message->sender->name }}</label>
                <textarea id="body" name="body" rows="4" required>{{ old('body') }}</textarea>
            </div>
            <button class="btn">Kirim balasan</button>
        </form>
    @endif
@endsection
