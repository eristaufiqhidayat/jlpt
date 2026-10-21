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

    {{-- Tab per kelompok (Kelas Basic, Kelas SSW, ...). Arahkan mouse / klik kelompok untuk memilih kurikulumnya. --}}
    <nav class="tabs" aria-label="Kurikulum">
        @foreach ($tabs->groupBy(fn ($t) => $t['group'] ?? '#'.$t['key']) as $groupKey => $items)
            @if (str_starts_with($groupKey, '#') || $items->count() === 1)
                @foreach ($items as $t)
                    <a href="{{ route('manage.materials.index', ['tab' => $t['key'], 'sort' => $sort]) }}" @class(['active' => $tab['key'] === $t['key'], 'locked' => $t['inactive']])
                       @if ($tab['key'] === $t['key']) aria-current="page" @endif>{{ $t['label'] }} <small>{{ $t['count'] }}</small></a>
                @endforeach
            @else
                @php($current = $items->firstWhere('key', $tab['key']))
                <div class="tab-group" data-tab-group>
                    <button type="button" @class(['tab-group-btn', 'active' => $current]) aria-haspopup="true" aria-expanded="false">
                        {{ $groupKey }}@if ($current)<span class="tab-current">· {{ $current['label'] }}</span>@endif
                        <small>{{ $items->sum('count') }}</small><span class="caret" aria-hidden="true">▾</span>
                    </button>
                    <div class="tab-menu" role="menu">
                        @foreach ($items as $t)
                            <a role="menuitem" href="{{ route('manage.materials.index', ['tab' => $t['key'], 'sort' => $sort]) }}" @class(['active' => $tab['key'] === $t['key'], 'locked' => $t['inactive']])
                               @if ($tab['key'] === $t['key']) aria-current="page" @endif><span>{{ $t['label'] }}</span> <small>{{ $t['count'] }}</small></a>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    </nav>
    <script>
        // Klik kelompok untuk membuka daftar kurikulum (untuk layar sentuh); di desktop cukup arahkan mouse.
        document.querySelectorAll('[data-tab-group]').forEach(function (group) {
            var btn = group.querySelector('button');
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = !group.classList.contains('open');
                document.querySelectorAll('[data-tab-group].open').forEach(function (g) { g.classList.remove('open'); g.querySelector('button').setAttribute('aria-expanded', 'false'); });
                group.classList.toggle('open', open);
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });
        document.addEventListener('click', function () {
            document.querySelectorAll('[data-tab-group].open').forEach(function (g) { g.classList.remove('open'); g.querySelector('button').setAttribute('aria-expanded', 'false'); });
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') document.dispatchEvent(new Event('click')); });
    </script>

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
                        <td><a href="{{ route('manage.materials.show', $m) }}"><strong>{{ $m->title }}</strong></a>@if ($m->category)<br><small>{{ $m->category }}</small>@endif</td>
                        <td>{{ $m->classroom?->name ?? '–' }}</td>
                        <td>{{ $m->author?->name ?? '–' }}</td>
                        <td>@if ($m->available_until){{ $m->available_until->translatedFormat('d M Y') }}@unless ($m->isAvailable()) <span class="badge">lewat</span>@endunless @else – @endif</td>
                        <td class="num">{{ $m->completed_by_count }}</td>
                        <td>{!! $m->status === 'published' ? '<span class="badge ok">Dibuka</span>' : '<span class="badge">Ditutup</span>' !!}</td>
                    @else
                        <td class="num">{{ $m->chapter_no ?? '–' }}</td>
                        <td><a href="{{ route('manage.materials.show', $m) }}"><strong>{{ $m->title }}</strong></a>@if ($m->chapter)<br><small>{{ $m->chapter }}</small>@endif</td>
                        <td style="white-space:nowrap">@if ($m->video_url || $m->hasFiles('video'))🎬@if ($m->filesOfType('video')->count() > 1)×{{ $m->filesOfType('video')->count() }}@endif @endif @if ($m->hasFiles('pdf'))📄@if ($m->filesOfType('pdf')->count() > 1)×{{ $m->filesOfType('pdf')->count() }}@endif @endif @if ($m->hasFiles('ppt'))📊@if ($m->filesOfType('ppt')->count() > 1)×{{ $m->filesOfType('ppt')->count() }}@endif @endif @if ($m->hasFiles('audio'))🎧@if ($m->filesOfType('audio')->count() > 1)×{{ $m->filesOfType('audio')->count() }}@endif @endif @if ($m->exam_id)✏️ @endif</td>
                        <td>{{ $m->level?->code ?? 'Semua' }}</td>
                        <td class="num">{{ $m->completed_by_count }}</td>
                        <td>{!! $m->status === 'published' ? '<span class="badge ok">Terbit</span>' : '<span class="badge">Draft</span>' !!}</td>
                    @endif
                    <td class="actions">
                        <a class="btn small" href="{{ route('manage.materials.show', $m) }}">Lihat</a>
                        @if ($editable)
                            <a class="btn small secondary" href="{{ route('manage.materials.edit', $m) }}">Edit</a>
                            <form method="POST" action="{{ route('manage.materials.destroy', $m) }}" style="display:inline" onsubmit="return confirm('Hapus materi ini beserta filenya?')">
                                @csrf @method('DELETE') <button class="btn small danger">Hapus</button>
                            </form>
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
