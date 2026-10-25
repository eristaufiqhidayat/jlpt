{{-- Peringatan untuk pengawas; ikut diperbarui otomatis. --}}
@if ($overdue)
    <div class="alert error">
        <strong>⚠ {{ $overdue }} peserta sudah lewat waktu lebih dari {{ \App\Http\Controllers\Proctor\MonitorController::OVERDUE_MINUTES }} menit tetapi sesinya belum dikumpulkan.</strong>
        Biasanya ini berarti cron <code>schedule:run</code> di server tidak berjalan. Minta admin mengecek Cron Jobs di hosting.
    </div>
@endif
@if ($stats['away'])
    <div class="alert warn">
        <strong>👁 {{ $stats['away'] }} peserta yang sedang mengerjakan pernah keluar dari halaman ujian</strong> (pindah tab, jendela, atau aplikasi). Baris mereka ditandai kuning.
    </div>
@endif
