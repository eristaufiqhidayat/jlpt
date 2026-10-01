<?php

namespace App\Enums;

/**
 * Status kunci jawaban sebuah file soal lama.
 */
enum LegacyKeyStatus: string
{
    case Ok = 'ok';                     // semua nomor punya kunci A–D
    case Incomplete = 'incomplete';     // ada nomor tanpa kunci
    case InvalidKeys = 'invalid_keys';  // ada kunci Z/E/F/kosong/duplikat (bug input aplikasi lama)
    case NoKeyNeeded = 'no_key_needed'; // Interview / esai Kanji, dinilai manual

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Lengkap',
            self::Incomplete => 'Kunci belum lengkap',
            self::InvalidKeys => 'Ada kunci tidak valid',
            self::NoKeyNeeded => 'Tanpa kunci (dinilai manual)',
        };
    }
}
