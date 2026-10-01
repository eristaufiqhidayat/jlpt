<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Exceptions\ExamAccessException;
use App\Models\Exam;
use App\Models\User;

/**
 * Aturan siapa boleh mulai ujian.
 */
class ExamAccessService
{
    public function findOpenExamByCode(string $code): Exam
    {
        $exam = Exam::with('sections')->where('code', strtoupper(trim($code)))->first();

        if (! $exam) {
            throw new ExamAccessException('Kode ujian tidak ditemukan.', 'not_found');
        }

        if (! $exam->isOpen()) {
            throw new ExamAccessException('Ujian ini sedang tidak dibuka.', 'closed');
        }

        return $exam;
    }

    public function hasPaid(User $student, Exam $exam): bool
    {
        if (! $exam->is_paid) {
            return true;
        }

        return $exam->payments()
            ->where('student_id', $student->id)
            ->where('status', PaymentStatus::Paid)
            ->exists();
    }

    /**
     * @throws ExamAccessException
     */
    public function ensureCanStart(User $student, Exam $exam): void
    {
        if (! $student->isStudent() || ! $student->is_active) {
            throw new ExamAccessException('Akun ini tidak bisa mengikuti ujian.');
        }

        if (! $exam->isOpen()) {
            throw new ExamAccessException('Ujian ini sedang tidak dibuka.', 'closed');
        }

        if (! $this->hasPaid($student, $exam)) {
            throw new ExamAccessException('Ujian ini berbayar. Selesaikan pembayaran terlebih dahulu.', 'payment_required');
        }

        if ($exam->sections->isEmpty()) {
            throw new ExamAccessException('Ujian ini belum memiliki bagian soal.', 'not_ready');
        }
    }
}
