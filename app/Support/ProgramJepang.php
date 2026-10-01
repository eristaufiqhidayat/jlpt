<?php

namespace App\Support;

/** Tahapan dan dokumen program ke Jepang (sesuai mockup JGG). */
final class ProgramJepang
{
    public const STAGES = [
        'pendaftaran' => 'Pendaftaran',
        'belajar' => 'Belajar Jepang',
        'jlpt' => 'JLPT',
        'interview' => 'Interview',
        'mcu' => 'MCU',
        'visa' => 'Visa',
        'berangkat' => 'Keberangkatan',
    ];

    public const DOCUMENTS = [
        'paspor' => 'Paspor',
        'sertifikat_jlpt' => 'Sertifikat JLPT',
        'interview' => 'Hasil Interview',
        'mcu' => 'Medical Check Up',
        'visa' => 'Visa',
        'tiket' => 'Tiket',
    ];

    public const STATUSES = ['belum' => 'Belum', 'proses' => 'Proses', 'selesai' => 'Selesai'];

    /** Persentase tahapan selesai (proses dihitung setengah). */
    public static function progress(iterable $stages): int
    {
        $by = collect($stages)->keyBy('stage');
        $score = 0;
        foreach (array_keys(self::STAGES) as $key) {
            $status = $by->get($key)?->status;
            $score += $status === 'selesai' ? 1 : ($status === 'proses' ? 0.5 : 0);
        }

        return (int) round($score / count(self::STAGES) * 100);
    }
}
