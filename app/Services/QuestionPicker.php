<?php

namespace App\Services;

use App\Enums\QuestionType;
use App\Models\Question;
use Illuminate\Support\Collection;

/**
 * Mengambil soal acak dari bank soal untuk satu bagian.
 *
 * Hanya soal dari sumber yang sesuai: 'practice' (Latihan + Keduanya) atau 'test' (Tes online + Keduanya).
 *
 * Soal yang berbagi satu bacaan/audio (passage) diambil sebagai satu kelompok utuh,
 * supaya student tidak mendapat bacaan yang soalnya terpotong.
 */
class QuestionPicker
{
    /**
     * @return Collection<int, Question> soal terurut, siap disalin ke attempt_questions
     */
    public function pick(int $sectionId, int $count, string $pool = 'practice'): Collection
    {
        $questions = Question::published()
            ->forPool($pool)
            ->where('section_id', $sectionId)
            // Pilihan ganda harus punya kunci; esai tidak memakai kunci.
            ->where(fn ($q) => $q->where('type', QuestionType::Essay)->orWhereHas('correctOption'))
            ->with('correctOption:id,question_id')
            ->get(['id', 'section_id', 'passage_id', 'number_in_passage', 'type']);

        // Kelompokkan: passage = satu unit berisi beberapa soal, soal mandiri = unit berisi satu soal.
        $units = $questions
            ->groupBy(fn (Question $q) => $q->passage_id ? 'p'.$q->passage_id : 'q'.$q->id)
            ->map(fn (Collection $group) => $group->sortBy('number_in_passage')->values())
            ->shuffle();

        $picked = collect();
        foreach ($units as $unit) {
            if ($picked->count() >= $count) {
                break;
            }
            if ($picked->count() + $unit->count() > $count) {
                continue; // unit tidak muat; coba unit lain yang lebih kecil
            }
            $picked = $picked->concat($unit);
        }

        // Urutkan per jenis soal (isian → ★ → bacaan → choukai) seperti susunan mondai JLPT.
        $typeOrder = ['blank' => 1, 'star' => 2, 'reading' => 3, 'listening' => 4, 'essay' => 5];

        return $picked
            ->sortBy(fn (Question $q) => sprintf(
                '%d-%010d-%05d',
                $typeOrder[$q->type->value] ?? 9,
                $q->passage_id ?? 0,
                $q->number_in_passage ?? 0,
            ))
            ->values();
    }
}
