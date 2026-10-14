@extends('layouts.app')
@section('title', 'Data Master')
@section('content')
@php
        $toggle = function ($entity, $model) {
            $label = $model->is_active ? 'Nonaktifkan' : 'Aktifkan';
            $message = 'Nonaktifkan "'.$model->masterLabel().'"? Data lama tetap tersimpan, tetapi tidak muncul lagi sebagai pilihan baru.';
            $confirm = $model->is_active ? 'return confirm('.e(json_encode($message, JSON_UNESCAPED_UNICODE)).')' : '';
    
            return '<form method="POST" action="'.route('manage.master.toggle', [$entity, $model->id]).'" style="display:inline" onsubmit="'.$confirm.'">'
                .csrf_field().'<button class="btn small secondary">'.$label.'</button></form>';
        };
        $status = fn ($active) => $active ? '<span class="badge ok">Aktif</span>' : '<span class="badge">Nonaktif</span>';
    @endphp
    <div class="page-head">
        <div>
            <h1>Data Master Akademik</h1>
            <p>Dasar untuk kelas, materi, soal latihan, dan ujian.
                @if ($canEdit) Data yang sudah dipakai tidak dihapus, cukup dinonaktifkan. @else Hanya Admin Pendidikan yang bisa mengubah; Anda melihat dalam mode baca. @endif</p>
        </div>
    </div>

    <nav class="tabs" aria-label="Jenis data master">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('manage.master.index', ['tab' => $key]) }}" @class(['active' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    {{-- ===================== Klasifikasi Kelas ===================== --}}
    @if ($tab === 'kelas')
        <div class="page-head" style="margin-bottom:12px">
            <p>Urutan menentukan lock: peserta harus naik kelas untuk membuka kurikulum berikutnya. Kurikulum dipilih saat membuat kelas.</p>
            @if ($canEdit)<a class="btn" href="{{ route('manage.master.create', 'curriculum') }}">+ Tambah kurikulum</a>@endif
        </div>
        <div class="card flush">
            <div class="table-wrap"><table>
                <tr><th class="num">Urutan</th><th>Kelompok</th><th>Kurikulum</th><th>Cakupan</th><th class="num">Kelas aktif</th><th class="num">Peserta</th><th>Status</th>@if ($canEdit)<th></th>@endif</tr>
                @forelse ($curricula as $c)
                    <tr>
                        <td class="num">{{ $c->sort_order }}</td>
                        <td>{{ $c->group }}</td>
                        <td><strong>{{ $c->name }}</strong><br><small>{{ $c->code }}</small></td>
                        <td>{{ collect([$c->chapterRange(), $c->scope])->filter()->implode(' · ') ?: '–' }}</td>
                        <td class="num">{{ $c->active_classes_count }}</td>
                        <td class="num">{{ $studentsPerCurriculum[$c->id] ?? 0 }}</td>
                        <td>{!! $status($c->is_active) !!}</td>
                        @if ($canEdit)
                            <td class="actions"><a class="btn small secondary" href="{{ route('manage.master.edit', ['curriculum', $c->id]) }}">Ubah</a> {!! $toggle('curriculum', $c) !!}</td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">Belum ada kurikulum.</td></tr>
                @endforelse
            </table></div>
        </div>
    @endif

    {{-- ===================== Klasifikasi Ujian ===================== --}}
    @if ($tab === 'ujian')
        <div class="page-head" style="margin-bottom:12px">
            <p>Jenis tes → level → bagian. Durasi bagian adalah nilai awal; sensei bisa mengubahnya per ujian.</p>
            @if ($canEdit)<a class="btn" href="{{ route('manage.master.create', 'test-type') }}">+ Tambah jenis tes</a>@endif
        </div>
        @foreach ($testTypes as $type)
            <div class="card">
                <div class="card-head">
                    <h2>{{ $type->name }} <small class="muted">· {{ $type->code }}</small> {!! $status($type->is_active) !!}</h2>
                    @if ($canEdit)
                        <div class="actions">
                            <a class="btn small" href="{{ route('manage.master.create', ['level', 'test_type_id' => $type->id]) }}">+ Level</a>
                            <a class="btn small secondary" href="{{ route('manage.master.edit', ['test-type', $type->id]) }}">Ubah</a>
                            {!! $toggle('test-type', $type) !!}
                        </div>
                    @endif
                </div>
                @if ($type->levels->isEmpty())
                    <p class="muted" style="margin:0">Belum ada level.</p>
                @else
                    <div class="table-wrap"><table>
                        <tr><th>Level</th><th>Bagian (durasi standar)</th><th class="num">Ujian</th><th>Status</th>@if ($canEdit)<th></th>@endif</tr>
                        @foreach ($type->levels as $level)
                            <tr>
                                <td><strong>{{ $level->code }}</strong><br><small>{{ $level->name }}</small></td>
                                <td>
                                    @forelse ($level->sections as $section)
                                        <div class="master-sub">
                                            <span @class(['muted' => ! $section->is_active])>{{ $section->name }} · {{ $section->default_duration_minutes }} mnt · {{ $section->questions_count }} soal</span>
                                            @unless ($section->is_active)<span class="badge">Nonaktif</span>@endunless
                                            @if ($canEdit)
                                                <a class="btn small secondary" href="{{ route('manage.master.edit', ['section', $section->id]) }}">Ubah</a> {!! $toggle('section', $section) !!}
                                            @endif
                                        </div>
                                    @empty
                                        <span class="muted">Belum ada bagian.</span>
                                    @endforelse
                                </td>
                                <td class="num">{{ $level->exams_count }}</td>
                                <td>{!! $status($level->is_active) !!}</td>
                                @if ($canEdit)
                                    <td class="actions">
                                        <a class="btn small" href="{{ route('manage.master.create', ['section', 'level_id' => $level->id]) }}">+ Bagian</a>
                                        <a class="btn small secondary" href="{{ route('manage.master.edit', ['level', $level->id]) }}">Ubah</a>
                                        {!! $toggle('level', $level) !!}
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </table></div>
                @endif
            </div>
        @endforeach
    @endif

    {{-- ===================== Skor & Batas Lulus ===================== --}}
    @if ($tab === 'skor')
        <div class="alert warn">Angka skor dan batas lulus diisi setelah disetujui manajemen. Tiap soal bernilai 1 poin; nilai akhir dikoreksi manual ke skala skor maksimal.
            Catatan: perhitungan nilai ujian saat ini masih persentase 0–100. Skala ini mulai dipakai setelah fitur koreksi nilai dibuat.</div>
        <div class="card flush">
            <div class="table-wrap"><table>
                <tr><th>Jenis tes</th><th class="num">Skor maks</th><th class="num">Batas lulus</th><th>Disetujui</th><th>Status</th>@if ($canEdit)<th></th>@endif</tr>
                @foreach ($testTypes as $type)
                    <tr>
                        <td><strong>{{ $type->name }}</strong><br><small>{{ $type->code }}</small></td>
                        <td class="num">{{ $type->max_score ?? '–' }}</td>
                        <td class="num">{{ $type->pass_score ?? '–' }}</td>
                        <td>
                            @if ($type->max_score)
                                <span class="badge ok">{{ $type->score_approved_by }}@if ($type->score_approved_at) · {{ $type->score_approved_at->translatedFormat('d M Y') }}@endif</span>
                            @else
                                <span class="badge warn">Belum ditentukan</span>
                            @endif
                        </td>
                        <td>{!! $status($type->is_active) !!}</td>
                        @if ($canEdit)
                            <td class="actions"><a class="btn small secondary" href="{{ route('manage.master.edit', ['test-type', $type->id, 'from' => 'skor']) }}">{{ $type->max_score ? 'Ubah' : 'Isi' }}</a></td>
                        @endif
                    </tr>
                @endforeach
            </table></div>
        </div>
    @endif

    {{-- ===================== Kategori Batsu ===================== --}}
    @if ($tab === 'batsu')
        <div class="page-head" style="margin-bottom:12px">
            <p>Kategori yang nanti dipilih sensei saat mencatat pelanggaran. Pencatatan batsu dibuat di tahap berikutnya.</p>
            @if ($canEdit)<a class="btn" href="{{ route('manage.master.create', 'violation-category') }}">+ Tambah kategori</a>@endif
        </div>
        <div class="card flush">
            <div class="table-wrap"><table>
                <tr><th>Kategori</th><th>Contoh / keterangan</th><th class="num">Urutan</th><th>Status</th>@if ($canEdit)<th></th>@endif</tr>
                @forelse ($categories as $cat)
                    <tr>
                        <td><span class="badge {{ $cat->color === 'grey' ? '' : $cat->color }}">{{ $cat->name }}</span></td>
                        <td>{{ $cat->description ?? '–' }}</td>
                        <td class="num">{{ $cat->sort_order }}</td>
                        <td>{!! $status($cat->is_active) !!}</td>
                        @if ($canEdit)
                            <td class="actions"><a class="btn small secondary" href="{{ route('manage.master.edit', ['violation-category', $cat->id]) }}">Ubah</a> {!! $toggle('violation-category', $cat) !!}</td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">Belum ada kategori batsu.@if ($canEdit) Tambahkan kategori pertama, mis. Terlambat atau Alpa.@endif</td></tr>
                @endforelse
            </table></div>
        </div>
    @endif

    {{-- ===================== Log perubahan ===================== --}}
    <div class="card">
        <div class="card-head"><h2>Perubahan terakhir</h2><small class="muted">10 perubahan terbaru</small></div>
        @forelse ($logs as $log)
            <div class="list-row">
                <div style="flex:1;min-width:0">
                    <strong>{{ $log->actionLabel() }}: {{ $log->subject_label }}</strong>
                    @foreach ($log->changes ?? [] as $change)
                        <br><small>{{ $change['field'] }}: {{ $change['old'] ?? '–' }} → {{ $change['new'] ?? '–' }}</small>
                    @endforeach
                </div>
                <small class="muted" style="text-align:right;white-space:nowrap">{{ $log->user?->name ?? 'Sistem' }}<br>{{ $log->created_at?->translatedFormat('d M Y, H.i') }}</small>
            </div>
        @empty
            <p class="muted" style="margin:0">Belum ada perubahan.</p>
        @endforelse
    </div>
@endsection
