<?php

namespace App\Enums;

enum QuestionType: string
{
    case Blank = 'blank';         // kalimat dengan satu tempat kosong
    case Star = 'star';           // menyusun kalimat, jawaban di posisi ★
    case Reading = 'reading';     // soal bacaan (dokkai)
    case Listening = 'listening'; // soal choukai
    case Essay = 'essay';         // jawaban ditulis student, dinilai manual oleh sensei (mis. tes Kanji)

    public function isAutoGraded(): bool
    {
        return $this !== self::Essay;
    }
}
