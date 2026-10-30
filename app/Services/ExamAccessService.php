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
    public function findOpenExamByCode(string $code, ?User $user = null): Exam
    {
        $exam = Exam::with('sections')->where('code', strtoupper(trim($code)))->first();

        if (! $exam) {
            throw new ExamAccessException('Kode ujian tidak ditemukan.', 'not_found');
        }

        if ($user && ! $exam->isForUser($user)) {
            throw new ExamAccessException($this->audienceMessage($user), 'audience');
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
        if (! $student->isExamTaker() || ! $student->is_active) {
            throw new ExamAccessException('Akun ini tidak bisa mengikuti ujian.');
        }

        if (! $exam->isForUser($student)) {
            throw new ExamAccessException($this->audienceMessage($student), 'audience');
        }

        if (! $exam->isOpen()) {
            throw new ExamAccessException('Ujian ini sedang tidak dibuka.', 'closed');
        }

        if (! $this->hasPaid($student, $exam)) {
            throw new ExamAccessException('Ujian ini berbayar. Selesaikan pembayaran terlebih dahulu.', 'payment_required');
        }

        if ($exam->remainingAttemptsFor($student) === 0) {
            throw new ExamAccessException(
                'Anda sudah mencapai batas pengerjaan ujian ini ('.$exam->max_attempts.'×). Hubungi admin bila perlu mengulang.',
                'attempt_limit',
            );
        }

        if ($exam->sections->isEmpty()) {
            throw new ExamAccessException('Ujian ini belum memiliki bagian soal.', 'not_ready');
        }
    }

    private function audienceMessage(User $user): string
    {
        return $user->isTestTaker()
            ? 'Kode ini untuk ujian internal siswa LPK, bukan untuk peserta tes umum.'
            : 'Kode ini untuk ujian peserta tes umum (tes online), bukan untuk siswa LPK.';
    }
}
