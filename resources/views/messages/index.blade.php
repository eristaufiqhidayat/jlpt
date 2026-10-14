@extends('layouts.app')
@section('title', 'Pesan')
@section('content')
    <div class="page-head">
        <div><h1>Pesan</h1><p>Pengumuman dan pesan dari sensei/admin.</p></div>
        @if (auth()->user()->isAcademicAdmin() || auth()->user()->isSensei())
            <a class="btn" href="{{ route('messages.create') }}">+ Tulis pesan</a>
        @endif
    </div>
    <div class="grid" style="grid-template-columns:minmax(0,1.6fr) minmax(260px,1fr);align-items:start">
        <div class="card">
            <div class="card-head"><h2>Kotak masuk</h2></div>
            @forelse ($messages as $m)
                <a class="list-row" href="{{ route('messages.show', $m) }}" style="text-decoration:none;color:inherit">
                    <div @class(['avatar', 'sm'])>{{ $m->sender?->initials() ?? '–' }}</div>
                    <div class="info">
                        <p style="{{ $m->read_at ? '' : 'font-weight:800' }}">{{ $m->subject }} @unless ($m->read_at)<span class="badge">baru</span>@endunless</p>
                        <span>{{ $m->sender?->name ?? 'Sistem' }} · {{ $m->created_at->diffForHumans() }} · {{ \Illuminate\Support\Str::limit($m->body, 70) }}</span>
                    </div>
                </a>
            @empty
                <p class="muted">Belum ada pesan.</p>
            @endforelse
            {{ $messages->links() }}
        </div>
        <div class="card">
            <div class="card-head"><h2>Terkirim</h2></div>
            @forelse ($sent as $m)
                <a class="list-row" href="{{ route('messages.show', $m) }}" style="text-decoration:none;color:inherit">
                    <div class="info"><p>{{ $m->subject }}</p><span>ke {{ $m->recipient?->name }} · {{ $m->created_at->translatedFormat('d M H:i') }}</span></div>
                </a>
            @empty
                <p class="muted">Belum ada pesan terkirim.</p>
            @endforelse
        </div>
    </div>
@endsection
