{{-- Baris tabel pengawasan; dipakai saat halaman dibuka dan saat pembaruan otomatis. --}}
@forelse ($attempts as $a)
    @php
        $sections = $a->sections;
        $running = $sections->first(fn ($s) => $s->isRunning());
        $index = $running ? $sections->search(fn ($s) => $s->is($running)) + 1 : null;
        $ongoing = $a->status === \App\Enums\AttemptStatus::Ongoing;
        $online = $ongoing && $a->last_seen_at?->gte($onlineSince);
        $type = $a->exam->level->testType;
        $manage = $canManage($a->exam);
        $awayFlag = $ongoing && $a->focus_lost_count > 0;
    @endphp
    <tr @class(['pr-done' => ! $ongoing, 'pr-away' => $awayFlag])>
        <td>
            <div class="pr-name">
                @if ($ongoing)<span @class(['pr-dot', 'on' => $online]) title="{{ $online ? 'Online' : 'Tidak ada sinyal'.($a->last_seen_at ? ' sejak '.$a->last_seen_at->format('H:i') : '') }}"></span>@endif
                <strong>{{ $a->student->name }}</strong>
            </div>
            <span class="muted pr-sub">{{ $a->student->email }}</span>
            @if ($a->focus_lost_count)
                <div class="pr-away-info" title="Pindah tab, jendela, atau aplikasi selama ujian">👁 Keluar halaman {{ $a->focus_lost_count }}×@if ($a->last_focus_lost_at) · terakhir {{ $a->last_focus_lost_at->format('H:i') }}@endif</div>
            @endif
        </td>
        <td>
            <span class="badge info">{{ $type?->code ?? '—' }}</span>
            <span class="badge">{{ $a->exam->level->code }}</span>
            <span @class(['badge', 'warn' => $a->exam->questionPool() === 'test'])>{{ $a->exam->questionPool() === 'test' ? 'Tes' : 'Latihan' }}</span>
            <div class="pr-sub">{{ $a->exam->title }} · <code>{{ $a->exam->code }}</code></div>
        </td>
        <td>
            @if ($running)
                <strong>{{ $running->examSection->section->name }}</strong>
                <div class="pr-sub muted">Sesi {{ $index }} dari {{ $sections->count() }}</div>
            @elseif ($ongoing)
                <span class="muted">Pindah sesi…</span>
            @else
                <span class="muted">Semua sesi selesai</span>
            @endif
        </td>
        <td class="pr-num">
            @if ($running)
                <strong class="pr-big">{{ $a->current_question_no ? min($a->current_question_no, $running->questions_count) : '—' }}</strong><span class="muted"> / {{ $running->questions_count }}</span>
            @else
                <span class="muted">—</span>
            @endif
        </td>
        <td class="pr-num">
            @if ($running)
                {{ $running->answered_count }} / {{ $running->questions_count }}
                <div class="pr-bar"><span style="width:{{ $running->questions_count ? round($running->answered_count / $running->questions_count * 100) : 0 }}%"></span></div>
            @else
                <span class="muted">—</span>
            @endif
        </td>
        <td class="pr-num">
            @if ($running)
                <span class="pr-timer" data-deadline="{{ $running->deadline_at->getTimestampMs() }}">{{ gmdate($running->remainingSeconds() >= 3600 ? 'G:i:s' : 'i:s', $running->remainingSeconds()) }}</span>
                @if ($a->extra_minutes)<div class="pr-extra">+{{ $a->extra_minutes }} mnt @if ($a->extendedBy)oleh {{ $a->extendedBy->name }}@endif</div>@endif
            @else
                <span class="muted">—</span>
            @endif
        </td>
        <td>
            @if ($ongoing)
                <span class="badge ok">Mengerjakan</span>
            @elseif ($a->stopped_by)
                <span class="badge err">Dihentikan</span>
                <div class="pr-stop-reason">oleh {{ $a->stoppedBy?->name ?? 'pengawas' }}@if ($a->stop_reason) · {{ $a->stop_reason }}@endif</div>
            @elseif ($a->isPendingReview())
                <span class="badge warn">Selesai · esai</span>
            @else
                <span class="badge">Selesai</span>
            @endif
            <div class="pr-sub muted">Masuk {{ $a->started_at?->format('H:i') }}@if (! $ongoing && $a->finished_at) · selesai {{ $a->finished_at->format('H:i') }}@endif</div>
        </td>
        <td class="actions">
            @if ($ongoing && $manage)
                <div class="pr-actions">
                    @if ($running)
                        <form method="POST" action="{{ route('proctor.attempts.extend', $a) }}" data-extend
                              data-message="{{ 'Tambah waktu sesi '.$running->examSection->section->name.' untuk '.$a->student->name.'?' }}">
                            @csrf
                            <input type="hidden" name="minutes">
                            <button class="btn small secondary" type="submit">＋ Waktu</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('proctor.attempts.stop', $a) }}" data-confirm-stop
                          data-message="{{ 'Hentikan ujian '.$a->student->name.'? Jawaban yang sudah tersimpan langsung dinilai dan peserta tidak bisa melanjutkan.' }}">
                        @csrf
                        <input type="hidden" name="reason">
                        <button class="btn small secondary" type="submit">⏹ Hentikan</button>
                    </form>
                </div>
            @elseif ($ongoing)
                <span class="muted" style="font-size:11px">Ujian sensei lain</span>
            @endif
        </td>
    </tr>
@empty
    <tr><td colspan="8" class="muted" style="text-align:center;padding:28px">Belum ada peserta yang masuk ujian untuk filter ini.</td></tr>
@endforelse
