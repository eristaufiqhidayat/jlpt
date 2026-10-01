@php($left = $playLimit === null ? null : max(0, $playLimit - $item->audio_play_count))
<div class="audio-box" style="margin:8px 0 12px">
    <button type="button" class="btn secondary small" data-play="{{ route('student.attempts.play', [$attempt, $item->id]) }}" @disabled($left === 0)>▶ Putar audio</button>
    <small class="muted" data-plays>{{ $left === null ? '' : "Sisa putar: {$left}×" }}</small>
    {{-- controlsList mencegah unduh; tanpa kontrol maju/mundur agar sesuai aturan ujian --}}
    <audio hidden controlsList="nodownload noplaybackrate" style="width:100%;margin-top:6px"></audio>
</div>
