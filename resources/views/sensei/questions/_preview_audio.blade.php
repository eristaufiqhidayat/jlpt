{{-- Audio di pratinjau sensei: tombol sama seperti di ujian peserta, tanpa batas putar. --}}
<div class="audio-box" style="margin:8px 0 12px">
    <button type="button" class="btn secondary small" data-preview-play>▶ Putar audio</button>
    <small class="muted">Di ujian, jumlah putar dibatasi sesuai pengaturan ujian.</small>
    <audio hidden controls controlsList="nodownload noplaybackrate" preload="none" src="{{ route('media', $path) }}" style="width:100%;margin-top:6px"></audio>
</div>
