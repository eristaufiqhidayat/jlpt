<?php

namespace App\Models;

use App\Enums\ExamStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Exam extends Model
{
    protected $fillable = [
        'sensei_id', 'level_id', 'code', 'title', 'description', 'opens_at', 'closes_at',
        'is_paid', 'price', 'pass_score', 'audio_play_limit', 'shuffle_options', 'show_result_immediately', 'status', 'audience',
        'question_pool', 'max_attempts',
    ];

    /** Sasaran ujian => label. */
    public const AUDIENCES = [
        'internal' => 'Siswa LPK (LMS)',
        'public' => 'Peserta tes umum (tes online)',
        'both' => 'Keduanya',
    ];

    /** Sumber soal ujian => label. Kosong = otomatis mengikuti "Untuk siapa". */
    public const QUESTION_POOLS = [
        '' => 'Otomatis (ikut "Untuk siapa")',
        'practice' => 'Soal latihan',
        'test' => 'Soal tes online',
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'is_paid' => 'boolean',
            'price' => 'integer',
            'max_attempts' => 'integer',
            'shuffle_options' => 'boolean',
            'show_result_immediately' => 'boolean',
            'status' => ExamStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Exam $exam) {
            $exam->code ??= static::generateCode();
        });
    }

    /** Apakah akun ini boleh mengikuti ujian ini (siswa LPK vs peserta tes umum). */
    public function isForUser(User $user): bool
    {
        return match ($this->audience ?: 'internal') {
            'public' => $user->isTestTaker(),
            'both' => $user->isExamTaker(),
            default => $user->isStudent(),
        };
    }

    /**
     * Sumber soal yang dipakai: 'practice' (soal Latihan + Keduanya) atau 'test' (soal Tes online + Keduanya).
     * Otomatis: ujian khusus siswa LPK memakai soal latihan; ujian yang bisa diikuti peserta tes umum memakai soal tes.
     */
    /** Berapa kali lagi peserta ini boleh memulai ujian; null = tanpa batas. */
    public function remainingAttemptsFor(User $student): ?int
    {
        if (! $this->max_attempts) {
            return null;
        }

        return max(0, $this->max_attempts - $this->attempts()->where('student_id', $student->id)->count());
    }

    public function maxAttemptsLabel(): string
    {
        return $this->max_attempts ? $this->max_attempts.'× per peserta' : 'Tanpa batas';
    }

    public function questionPool(): string
    {
        if (in_array($this->question_pool, ['practice', 'test'], true)) {
            return $this->question_pool;
        }

        return ($this->audience ?: 'internal') === 'internal' ? 'practice' : 'test';
    }

    public function questionPoolLabel(): string
    {
        return self::QUESTION_POOLS[$this->questionPool()].($this->question_pool ? '' : ' (otomatis)');
    }

    /** Pembahasan (kunci per soal) hanya untuk ujian bersumber soal latihan, supaya kunci soal tes tidak bocor. */
    public function showsReview(): bool
    {
        return (bool) $this->show_result_immediately && $this->questionPool() === 'practice';
    }

    public function audienceLabel(): string
    {
        return self::AUDIENCES[$this->audience ?: 'internal'] ?? $this->audience;
    }

    /** Kode akses 6 karakter tanpa huruf/angka yang mirip (0/O, 1/I). */
    public static function generateCode(): string
    {
        do {
            $code = collect(str_split(str_repeat('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', 2)))
                ->shuffle()->take(6)->implode('');
        } while (static::where('code', $code)->exists());

        return $code;
    }

    public function sensei(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sensei_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ExamSection::class)->orderBy('sort_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isOpen(): bool
    {
        return $this->status === ExamStatus::Open
            && ($this->opens_at === null || $this->opens_at->isPast())
            && ($this->closes_at === null || $this->closes_at->isFuture());
    }

    /** null bila ujian tidak memakai nilai minimal lulus. */
    public function passed(?float $score): ?bool
    {
        return $this->pass_score === null || $score === null ? null : $score >= $this->pass_score;
    }

    public function totalDurationMinutes(): int
    {
        return (int) $this->sections->sum('duration_minutes');
    }
}
