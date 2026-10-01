<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Menampilkan teks soal Jepang dengan aman (di-escape), plus notasi sederhana:
 *
 *   {漢字|かんじ}   → furigana: <ruby>漢字<rt>かんじ</rt></ruby>
 *   __雨__          → garis bawah (kata yang ditanyakan)
 *   baris baru      → <br>
 *
 * Dipakai di Blade lewat @jp($teks).
 */
final class JpText
{
    public static function render(?string $text): HtmlString
    {
        $html = e((string) $text);

        // {漢字|かんじ}
        $html = preg_replace('/\{([^{}|]+)\|([^{}|]+)\}/u', '<ruby>$1<rt>$2</rt></ruby>', $html);
        // __teks__
        $html = preg_replace('/__(.+?)__/u', '<u>$1</u>', $html);

        return new HtmlString(nl2br($html, false));
    }

    /** Teks polos tanpa notasi (untuk judul, pencarian, ringkasan). */
    public static function plain(?string $text): string
    {
        $text = preg_replace('/\{([^{}|]+)\|[^{}|]+\}/u', '$1', (string) $text);

        return str_replace('__', '', $text);
    }
}
