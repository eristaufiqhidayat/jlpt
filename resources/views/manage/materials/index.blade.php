@extends('layouts.app')
@section('title', 'Materi')
@section('content')
    @php($isExtraTab = $tab['key'] === 'tambahan')
    <div class="page-head">
        <div><h1>Materi Pembelajaran</h1>
            <p>Materi pakem per kurikulum hanya diubah Admin Pendidikan. Sensei menambah materi tambahan untuk kelas yang dipegangnya.</p></div>
        <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap">
            @if ($canCreateCore)<a class="btn" href="{{ route('manage.materials.create', ['tab' => $tab['key']]) }}">+ Materi pakem</a>@endif
            @if ($canCreateExtra)<a @class(['btn', 'secondary' => $canCreateCore]) href="{{ route('manage.materials.create', ['kind' => 'extra']) }}">+ Materi tambahan</a>@endif
        </div>
    </div>

    <nav class="tabs" aria-label="Kurikulum">
        @foreach ($tabs as $t)
            <a href="{{ route('manage.materials.index', ['tab' => $t['key'], 'sort' => $sort]) }}" @class(['active' => $tab['key'] === $t['key'], 'locked' => $t['inactive']])
               @if ($tab['key'] === $t['key']) aria-current="page" @endif>{{ $t['label'] }} <small class="muted">{{ $t['count'] }}</small></a>
        @endforeach
    </nav>

    @if (! $isExtraTab && ! $canCreateCore)
        <div class="alert info">Mode baca: materi pakem hanya bisa diubah Admin Pendidikan.</div>
    @endif

    <form method="GET" class="filters">
        <input type="hidden" name="tab" value="{{ $tab['key'] }}">
        <div class="field" style="flex:1"><label for="q">Cari judul / bab</label><input id="q" type="search" name="q" value="{{ request('q') }}" placeholder="mis. ことができます atau 18"></div>
        <div class="field"><label for="sort">Urutkan</label>
            <select id="sort" name="sort" onchange="this.form.submit()">
                @foreach ($sorts as $key => $label)<option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>@endforeach
            </select></div>
        <button class="btn secondary">Cari</button>
    </form>

    <div class="card flush">
        <div class="table-wrap"><table>
            @if ($isExtraTab)
                <tr><th>Judul</th><th>Kelas</th><th>Sensei</th><th>Tayang s.d.</th><th class="num">Selesai</th><th>Akses</th><th></th></tr>
            @else
                <tr><th class="num">Bab</th><th>Judul</th><th>Isi</th><th>Level</th><th class="num">Selesai</th><th>Status</th><th></th></tr>
            @endif
            @forelse ($materials as $m)
                @php($editable = $m->isEditableBy(auth()->user()))
                <tr>
                    @if ($isExtraTab)
                        <td><strong>{{ $m->title }}</strong>@if ($m->category)<br><small>{{ $m->category }}</small>@endif</td>
                        <td>{{ $m->classroom?->name ?? '–' }}</td>
                        <td>{{ $m->author?->name ?? '–' }}</td>
                        <td>@if ($m->available_until){{ $m->available_until->translatedFormat('d M Y') }}@unless ($m->isAvailable()) <span class="badge">lewat</span>@endunless @else – @endif</td>
                        <td class="num">{{ $m->completed_by_count }}</td>
                        <td>{!! $m->status === 'published' ? '<span class="badge ok">Dibuka</span>' : '<span class="badge">Ditutup</span>' !!}</td>
                    @else
                        <td class="num">{{ $m->chapter_no ?? '–' }}</td>
                        <td><strong>{{ $m->title }}</strong>@if ($m->chapter)<br><small>{{ $m->chapter }}</small>@endif</td>
                        <td style="white-space:nowrap">@if ($m->video_url || $m->video_path)🎬 @endif @if ($m->pdf_path)📄 @endif @if ($m->ppt_path)📊 @endif @if ($m->exam_id)✏️ @endif</td>
                        <td>{{ $m->level?->code ?? 'Semua' }}</td>
                        <td class="num">{{ $m->completed_by_count }}</td>
                        <td>{!! $m->status === 'published' ? '<span class="badge ok">Terbit</span>' : '<span class="badge">Draft</span>' !!}</td>
                    @endif
                    <td class="actions">
                        @if ($editable)
                            <a class="btn small secondary" href="{{ route('manage.materials.edit', $m) }}">Edit</a>
                            <form method="POST" action="{{ route('manage.materials.destroy', $m) }}" style="display:inline" onsubmit="return confirm('Hapus materi ini beserta filenya?')">
                                @csrf @method('DELETE') <button class="btn small danger">Hapus</button>
                            </form>
                        @else
                            <span class="muted" style="font-size:12px">lihat saja</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Belum ada materi di tab ini.</td></tr>
            @endforelse
        </table></div>
    </div>
    {{ $materials->links() }}
@endsection
