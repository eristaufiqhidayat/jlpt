@extends('layouts.app')
@section('title', 'Materi')
@section('content')
    <div class="page-head">
        <div><h1>Materi Pembelajaran</h1><p>Video, PDF, PPT, dan latihan soal per bab.</p></div>
        <a class="btn" href="{{ route('manage.materials.create') }}">+ Tambah materi</a>
    </div>
    <form method="GET" class="filters">
        <div class="field">
            <label for="level_id">Level</label>
            <select id="level_id" name="level_id" onchange="this.form.submit()">
                <option value="">Semua</option>
                @foreach ($levels as $lv)<option value="{{ $lv->id }}" @selected((int) request('level_id') === $lv->id)>{{ $lv->testType->code }} {{ $lv->code }}</option>@endforeach
            </select>
        </div>
        <div class="field" style="flex:1"><label for="q">Cari judul / bab</label><input id="q" type="search" name="q" value="{{ request('q') }}"></div>
        <button class="btn secondary">Cari</button>
    </form>
    <div class="card" style="padding:0">
        <div class="table-wrap"><table>
            <tr><th>Judul</th><th>Level</th><th>Bab</th><th>Isi</th><th>Selesai</th><th>Status</th><th></th></tr>
            @forelse ($materials as $m)
                <tr>
                    <td><strong>{{ $m->title }}</strong>@if ($m->category)<br><small>{{ $m->category }}</small>@endif</td>
                    <td>{{ $m->level?->code ?? 'Semua' }}</td>
                    <td>{{ $m->chapter ?? '–' }}</td>
                    <td style="white-space:nowrap">@if ($m->video_url || $m->video_path)🎬 @endif @if ($m->pdf_path)📄 @endif @if ($m->ppt_path)📊 @endif @if ($m->exam_id)✏️ @endif</td>
                    <td>{{ $m->completed_by_count }}</td>
                    <td>{!! $m->status === 'published' ? '<span class="badge ok">Terbit</span>' : '<span class="badge">Draft</span>' !!}</td>
                    <td class="actions">
                        <a class="btn small secondary" href="{{ route('manage.materials.edit', $m) }}">Edit</a>
                        <form method="POST" action="{{ route('manage.materials.destroy', $m) }}" style="display:inline" onsubmit="return confirm('Hapus materi ini beserta filenya?')">
                            @csrf @method('DELETE') <button class="btn small danger">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Belum ada materi.</td></tr>
            @endforelse
        </table></div>
    </div>
    {{ $materials->links() }}
@endsection
