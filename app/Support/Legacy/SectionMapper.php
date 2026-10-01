<?php

namespace App\Support\Legacy;

/**
 * Memetakan teks bebas aplikasi lama ke kode di tabel master.
 * Daftar berasal dari hasil query ket_soal per jenis_tes di database lama.
 */
final class SectionMapper
{
    public const SECTION_CODES = [
        'moji goi' => 'moji_goi',
        'bunpou dokkai' => 'bunpou_dokkai',
        'choukai' => 'choukai',
        'kanji-furigana' => 'kanji_furigana',
        'furigana-kanji' => 'furigana_kanji',
        'b.indonesia' => 'bahasa_indonesia',
        'b.jepang' => 'bahasa_jepang',
        'interview' => 'interview',
    ];

    public const TEST_TYPE_CODES = [
        'JLPT' => 'JLPT',
        'JTEST' => 'JTEST',
        'NATTEST' => 'NATTEST',
        'KANJI' => 'KANJI',
        'PROMETRIC' => 'PROMETRIC',
        'PROMETRICKANJI' => 'PROMETRICKANJI',
        'INTERVIEW' => 'INTERVIEW',
        'JFT' => 'JFT',
    ];

    /** Urutan bagian di dalam satu ujian. */
    public const SECTION_ORDER = [
        'moji_goi' => 1, 'bunpou_dokkai' => 2, 'choukai' => 3,
        'kanji_furigana' => 1, 'furigana_kanji' => 2,
        'bahasa_jepang' => 1, 'bahasa_indonesia' => 2,
        'interview' => 1,
    ];

    public static function sortOrder(string $code): int
    {
        return self::SECTION_ORDER[$code] ?? 9;
    }

    public static function sectionCode(?string $ketSoal): ?string
    {
        $key = strtolower(trim(preg_replace('/\s+/', ' ', (string) $ketSoal)));

        return self::SECTION_CODES[$key] ?? null;
    }

    public static function testTypeCode(?string $jenisTes): ?string
    {
        return self::TEST_TYPE_CODES[strtoupper(trim((string) $jenisTes))] ?? null;
    }

    /**
     * Tebakan jenis tes untuk file yang paketnya sudah tidak ada di m_exam.
     * Hanya JLPT yang ditebak: level N1–N5 dengan bagian Moji Goi / Bunpou Dokkai / Choukai.
     * (JTEST/NATTEST juga memakai kode N, jadi hasilnya tetap ditandai untuk dicek sensei.)
     */
    public static function guessTestTypeFromKodeExam(string $kodeExam, ?string $ketSoal): ?string
    {
        $level = self::levelFromKodeExam($kodeExam);
        $section = self::sectionCode($ketSoal);

        return ($level && preg_match('/^N[1-5]$/', $level) && in_array($section, ['moji_goi', 'bunpou_dokkai', 'choukai'], true))
            ? 'JLPT'
            : null;
    }

    /**
     * Level dari kode_exam, mis. "111009N5200701073135" → "N5", "111009JF1201102062903" → "JF1".
     * Dipakai untuk file yang kode_exam-nya tidak ada di m_exam.
     */
    public static function levelFromKodeExam(string $kodeExam): ?string
    {
        return preg_match('/^\d{6}([A-Z]+\d)\d{12}$/', $kodeExam, $m) ? $m[1] : null;
    }
}
