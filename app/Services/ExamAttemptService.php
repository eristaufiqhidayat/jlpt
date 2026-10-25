<?php

namespace App\Services;

use App\Enums\AttemptStatus;
use App\Exceptions\ExamAccessException;
use App\Models\AttemptQuestion;
use App\Models\Certificate;
use App\Models\AttemptSection;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alur pengerjaan ujian dengan waktu per bagian.
 *
 * 1. start()          : buat attempt, acak soal semua bagian, mulai bagian pertama.
 * 2. answer()         : simpan jawaban; ditolak bila waktu bagian sudah habis.
 * 3. submitSection()  : kumpulkan bagian (oleh student atau otomatis saat timeout),
 *                       nilai bagian itu, lalu langsung mulai bagian berikutnya.
 * 4. Setelah bagian terakhir, nilai total langsung keluar.
 *
 * Semua waktu memakai jam server; timer di browser hanya tampilan.
 */
class ExamAttemptService
{
    /** Toleransi keterlambatan jaringan saat menyimpan jawaban (detik). */
    public const GRACE_SECONDS = 5;

    public function __construct(
        private readonly ExamAccessService $access,
        private readonly QuestionPicker $picker,
    ) {}

    /**
     * Mulai ujian, atau lanjutkan attempt yang masih berjalan.
     */
    public function start(User $student, Exam $exam): ExamAttempt
    {
        $exam->loadMissing('sections');

        $ongoing = ExamAttempt::where('exam_id', $exam->id)
            ->where('student_id', $student->id)
            ->where('status', AttemptStatus::Ongoing)
            ->first();

        if ($ongoing) {
            $this->expireOverdueSections($ongoing);

            return $ongoing->fresh('sections');
        }

        $this->access->ensureCanStart($student, $exam);

        return DB::transaction(function () use ($student, $exam) {
            $attempt = ExamAttempt::create([
                'exam_id' => $exam->id,
                'student_id' => $student->id,
                'started_at' => now(),
                'status' => AttemptStatus::Ongoing,
            ]);

            $total = 0;
            foreach ($exam->sections as $examSection) {
                $questions = $this->picker->pick($examSection->section_id, $examSection->question_count, $exam->questionPool());

                if ($questions->isEmpty()) {
                    throw new ExamAccessException(
                        'Bank soal untuk bagian ini belum cukup. Hubungi sensei.', 'not_ready'
                    );
                }

                $attemptSection = $attempt->sections()->create([
                    'exam_section_id' => $examSection->id,
                    'sort_order' => $examSection->sort_order,
                    'duration_minutes' => $examSection->duration_minutes,
                    'question_count' => $questions->count(),
                ]);

                $rows = $questions->values()->map(fn ($q, $i) => [
                    'attempt_section_id' => $attemptSection->id,
                    'question_id' => $q->id,
                    'sort_order' => $i + 1,
                    'correct_option_id' => $q->correctOption?->id, // snapshot kunci; null untuk esai
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                AttemptQuestion::insert($rows->all());

                $total += $questions->count();
            }

            $attempt->update(['question_count' => $total]);
            $this->startSection($attempt->sections()->orderBy('sort_order')->first());

            return $attempt->fresh('sections');
        });
    }

    public function startSection(AttemptSection $section): void
    {
        if ($section->started_at !== null) {
            return;
        }

        $section->update([
            'started_at' => now(),
            'deadline_at' => now()->addMinutes($section->duration_minutes),
        ]);
    }

    /**
     * Simpan (atau ganti) jawaban satu soal.
     *
     * @throws ExamAccessException
     */
    public function answer(AttemptQuestion $item, ?int $optionId, bool $flagged = false): void
    {
        $section = $item->attemptSection;

        if (! $section->isRunning()) {
            throw new ExamAccessException('Bagian ini sudah ditutup.', 'section_closed');
        }

        if (now()->greaterThan($section->deadline_at->copy()->addSeconds(self::GRACE_SECONDS))) {
            $this->submitSection($section, 'timeout');
            throw new ExamAccessException('Waktu bagian ini sudah habis.', 'timeout');
        }

        if ($item->correct_option_id === null) {
            throw new ExamAccessException('Soal ini esai, gunakan jawaban tertulis.', 'invalid_option');
        }

        if ($optionId !== null && ! $item->question->options()->whereKey($optionId)->exists()) {
            throw new ExamAccessException('Pilihan jawaban tidak valid.', 'invalid_option');
        }

        $item->update([
            'selected_option_id' => $optionId,
            'is_flagged' => $flagged,
            'answered_at' => now(),
        ]);
    }

    /**
     * Simpan jawaban tertulis untuk soal esai.
     *
     * @throws ExamAccessException
     */
    public function answerEssay(AttemptQuestion $item, ?string $text, bool $flagged = false): void
    {
        $section = $item->attemptSection;

        if (! $section->isRunning()) {
            throw new ExamAccessException('Bagian ini sudah ditutup.', 'section_closed');
        }

        if (now()->greaterThan($section->deadline_at->copy()->addSeconds(self::GRACE_SECONDS))) {
            $this->submitSection($section, 'timeout');
            throw new ExamAccessException('Waktu bagian ini sudah habis.', 'timeout');
        }

        if ($item->correct_option_id !== null) {
            throw new ExamAccessException('Soal ini pilihan ganda.', 'invalid_option');
        }

        $text = $text === null ? null : mb_substr(trim($text), 0, 2000);

        $item->update([
            'answer_text' => $text === '' ? null : $text,
            'is_flagged' => $flagged,
            'answered_at' => now(),
        ]);
    }

    /**
     * Sensei menilai satu jawaban esai (benar/salah), lalu nilai bagian dan total dihitung ulang.
     */
    public function gradeEssay(AttemptQuestion $item, bool $isCorrect, User $grader, ?string $feedback = null): void
    {
        if ($item->correct_option_id !== null) {
            throw new ExamAccessException('Soal pilihan ganda dinilai otomatis.', 'invalid_option');
        }

        if ($item->attemptSection->submitted_at === null) {
            throw new ExamAccessException('Bagian ini belum selesai dikerjakan.', 'section_open');
        }

        DB::transaction(function () use ($item, $isCorrect, $grader, $feedback) {
            $item->update([
                'is_correct' => $isCorrect,
                'graded_by' => $grader->id,
                'graded_at' => now(),
                'feedback' => $feedback,
            ]);

            $this->scoreSection($item->attemptSection->fresh());

            $attempt = $item->attemptSection->attempt;
            if ($attempt->sections()->whereNull('submitted_at')->doesntExist()) {
                $this->finish($attempt);
            }
        });
    }

    /** Hitung nilai bagian dari soal yang sudah dinilai (esai yang belum dinilai dihitung belum benar). */
    private function scoreSection(AttemptSection $section): void
    {
        $correct = $section->questions()->where('is_correct', true)->count();
        $count = max(1, $section->question_count);

        $section->update([
            'correct_count' => $correct,
            'score' => round($correct / $count * 100, 2),
        ]);
    }

    /**
     * Kumpulkan dan nilai satu bagian, lalu mulai bagian berikutnya
     * atau selesaikan ujian bila ini bagian terakhir.
     */
    public function submitSection(AttemptSection $section, string $by = 'student'): void
    {
        DB::transaction(function () use ($section, $by) {
            $section = AttemptSection::whereKey($section->id)->lockForUpdate()->first();

            if ($section->submitted_at !== null) {
                return; // sudah dikumpulkan (mis. request ganda)
            }

            // Pilihan ganda dinilai otomatis dari snapshot kunci; esai menunggu sensei.
            $section->questions()->whereNotNull('correct_option_id')->update([
                'is_correct' => DB::raw('CASE WHEN selected_option_id IS NOT NULL AND selected_option_id = correct_option_id THEN 1 ELSE 0 END'),
            ]);
            // Esai yang tidak dijawab langsung dinilai salah, tidak perlu diperiksa sensei.
            $section->questions()->whereNull('correct_option_id')->whereNull('answer_text')->update([
                'is_correct' => false,
                'graded_at' => now(),
            ]);

            $section->update(['submitted_at' => now(), 'submitted_by' => $by]);
            $this->scoreSection($section);

            $attempt = $section->attempt()->with('sections')->first();
            $next = $attempt->sections->first(fn (AttemptSection $s) => $s->started_at === null);

            if ($next) {
                $this->startSection($next);
            } else {
                $this->finish($attempt);
            }
        });
    }

    /**
     * Hitung nilai total dan tutup attempt.
     * Bila masih ada esai yang belum dinilai, status menjadi pending_review sampai sensei selesai menilai.
     */
    public function finish(ExamAttempt $attempt): void
    {
        $attempt->load('sections');
        $correct = (int) $attempt->sections->sum('correct_count');
        $count = max(1, (int) $attempt->sections->sum('question_count'));

        $pending = AttemptQuestion::whereIn('attempt_section_id', $attempt->sections->pluck('id'))
            ->whereNull('correct_option_id')->whereNull('graded_at')->exists();

        $attempt->update([
            'status' => $pending ? AttemptStatus::PendingReview : AttemptStatus::Finished,
            'finished_at' => $attempt->finished_at ?? now(),
            'correct_count' => $correct,
            'score' => round($correct / $count * 100, 2),
        ]);

        if (! $pending) {
            $this->issueCertificate($attempt->fresh('exam.level.testType'));
        }
    }

    /**
     * Sertifikat otomatis bila ujian punya nilai minimal lulus dan peserta lulus.
     * Nomor: JGG-{JENIS TES}-{TAHUN}-{urut 4 digit}.
     */
    public function issueCertificate(ExamAttempt $attempt): ?Certificate
    {
        if ($attempt->exam->passed((float) $attempt->score) !== true) {
            return null;
        }

        return Certificate::firstOrCreate(['exam_attempt_id' => $attempt->id], function () use ($attempt) {
            $year = now()->year;
            $type = $attempt->exam->level->testType->code;
            $seq = Certificate::whereYear('issued_at', $year)->lockForUpdate()->count() + 1;

            return [
                'student_id' => $attempt->student_id,
                'exam_id' => $attempt->exam_id,
                'number' => sprintf('JGG-%s-%d-%04d', $type, $year, $seq),
                'score' => $attempt->score,
                'verify_token' => Str::lower(Str::random(32)),
                'issued_at' => now(),
            ];
        });
    }

    /**
     * Pengawas menghentikan ujian peserta: semua bagian yang belum dikumpulkan dikumpulkan dan dinilai
     * dengan jawaban yang sudah tersimpan (soal yang belum dijawab dihitung salah), lalu nilai keluar.
     * Mengembalikan false bila ujian peserta ini sudah selesai.
     */
    public function stopAttempt(ExamAttempt $attempt, User $by, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($attempt, $by, $reason) {
            $attempt = ExamAttempt::whereKey($attempt->id)->lockForUpdate()->first();
            if ($attempt->status !== AttemptStatus::Ongoing) {
                return false;
            }

            // submitSection memulai bagian berikutnya; ulangi sampai semua bagian terkumpul.
            while ($section = $attempt->sections()->whereNull('submitted_at')->orderBy('sort_order')->first()) {
                $this->startSection($section);
                $this->submitSection($section->fresh(), 'proctor');
            }

            $attempt->refresh();
            if ($attempt->status === AttemptStatus::Ongoing) {
                $this->finish($attempt); // mis. attempt tanpa bagian
            }
            $attempt->update(['stopped_by' => $by->id, 'stop_reason' => $reason ?: null]);

            return true;
        });
    }

    /**
     * Pengawas menambah waktu sesi yang sedang berjalan (mis. peserta terkendala internet).
     * Bila waktunya sudah lewat tetapi sesi belum dikumpulkan, tambahan dihitung dari sekarang.
     * Mengembalikan false bila tidak ada sesi yang sedang berjalan.
     */
    public function extendAttempt(ExamAttempt $attempt, User $by, int $minutes): bool
    {
        return DB::transaction(function () use ($attempt, $by, $minutes) {
            $attempt = ExamAttempt::whereKey($attempt->id)->lockForUpdate()->first();
            if ($attempt->status !== AttemptStatus::Ongoing) {
                return false;
            }

            $section = $attempt->sections()->whereNotNull('started_at')->whereNull('submitted_at')
                ->orderBy('sort_order')->lockForUpdate()->first();
            if (! $section) {
                return false;
            }

            $base = $section->deadline_at->isPast() ? now() : $section->deadline_at;
            $section->update(['deadline_at' => $base->copy()->addMinutes($minutes)]);
            $attempt->update(['extra_minutes' => $attempt->extra_minutes + $minutes, 'extended_by' => $by->id]);

            return true;
        });
    }

    /**
     * Kumpulkan otomatis bagian yang waktunya sudah habis.
     * Dipanggil saat student kembali, dan oleh scheduler untuk student yang menutup browser.
     */
    public function expireOverdueSections(ExamAttempt $attempt): void
    {
        // Loop karena bagian berikutnya bisa ikut habis bila student lama tidak kembali.
        while ($section = $attempt->sections()->whereNull('submitted_at')->whereNotNull('started_at')
            ->where('deadline_at', '<', now()->subSeconds(self::GRACE_SECONDS))->first()) {
            $this->submitSection($section, 'timeout');
        }
    }
}
