@extends('layouts.app')
@section('title', 'Bacaan & audio')
@section('content')
    <div class="page-head">
        <h1>Bacaan &amp; audio bersama</h1>
        <div>
            <a class="btn secondary" href="{{ route('sensei.questions.index') }}">Pelatihan Soal</a>
            <a class="btn" href="{{ route('sensei.passages.create') }}">+ Bacaan baru</a>
        </div>
    </div>
    <p class="muted">Satu bacaan (dokkai) atau satu audio (choukai) bisa dipakai beberapa soal. Saat ujian, soal-soal dari satu bacaan selalu keluar bersama.</p>

    <div class="card" style="padding:0">
        <div class="table-wrap"><table>
            <tr><th>#</th><th>Judul / isi</th><th>Bagian</th><th class="num">Soal</th><th>Status</th><th></th></tr>
            @forelse ($passages as $p)
                <tr>
                    <td>{{ $p->id }}</td>
                    <td>
                        <strong>{{ $p->title }}</strong>
                        <div class="muted">{{ \Illuminate\Support\Str::limit(\App\Support\JpText::plain($p->content), 100) }}</div>
                        @if ($p->image_path) <span class="badge">gambar</span>@endif
                        @if ($p->audio_path) <span class="badge">audio</span>@endif
                    </td>
                    <td>{{ $p->section->level->testType->code }} {{ $p->section->level->code }}<br><small>{{ $p->section->name }}</small></td>
                    <td class="num">{{ $p->questions_count }}</td>
                    <td>@include('sensei.questions._status', ['status' => $p->status->value])</td>
                    <td class="actions">
                        <a class="btn small secondary" href="{{ route('sensei.passages.edit', $p) }}">Edit</a>
                        <a class="btn small" href="{{ route('sensei.questions.create', ['section_id' => $p->section_id, 'passage_id' => $p->id, 'type' => $p->audio_path ? 'listening' : 'reading']) }}">+ Soal</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Belum ada bacaan.</td></tr>
            @endforelse
        </table></div>
    </div>
    {{ $passages->links() }}
@endsection
