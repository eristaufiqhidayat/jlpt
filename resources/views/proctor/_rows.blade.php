{{-- Baris tabel pengawasan; dipakai saat halaman dibuka dan saat pembaruan otomatis. --}}
@forelse ($attempts as $a)
    @php
        $sections = $a->sections;
        $running = $sections->first(fn ($s) => $s->isRunning());
        $index = $running ? $sections->search(fn ($s) => $s->is($running)) + 1 : null;
        $ongoing = $a->status === \App\Enums\AttemptStatus::Ongoing;
        $online = $ongoing && $a->last_seen_at?->gte($onlineSince);
        $type = $a->exam->level->testType;
    @endphp
    <tr @class(['pr-done' => ! $ongoing])>
        <td>
            <div class="pr-name">
                @if ($ongoing)<span @class(['pr-dot', 'on' => $online]) title="{{ $online ? 'Online' : 'Tidak ada sinyal'.($a->last_seen_at ? ' sejak '.$a->last_seen_at->format('H:i') : '') }}"></span>@endif
                <strong>{{ $a->student->name }}</strong>
            </div>
            <span class="muted pr-sub">{{ $a->student->email }}</span>
        </td>
        <td>
            <span class="badge info">{{ $type?->code ?? '—' }}</span>
            <span class="badge">{{ $a->exam->level->code }}</span>
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
            @else
                <span class="muted">—</span>
            @endif
        </td>
        <td>
            @if ($ongoing)
                <span class="badge ok">Mengerjakan</span>
            @elseif ($a->isPendingReview())
                <span class="badge warn">Selesai · esai</span>
            @else
                <span class="badge">Selesai</span>
            @endif
            <div class="pr-sub muted">Masuk {{ $a->started_at?->format('H:i') }}@if (! $ongoing && $a->finished_at) · selesai {{ $a->finished_at->format('H:i') }}@endif</div>
        </td>
    </tr>
@empty
    <tr><td colspan="7" class="muted" style="text-align:center;padding:28px">Belum ada peserta yang masuk ujian untuk filter ini.</td></tr>
@endforelse
