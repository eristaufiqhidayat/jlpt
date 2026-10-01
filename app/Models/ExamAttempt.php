<?php

namespace App\Models;

use App\Enums\AttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamAttempt extends Model
{
    protected $fillable = [
        'exam_id', 'student_id', 'started_at', 'finished_at',
        'correct_count', 'question_count', 'score', 'status',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'score' => 'decimal:2',
            'status' => AttemptStatus::class,
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(AttemptSection::class)->orderBy('sort_order');
    }

    /** Bagian yang sedang dikerjakan (sudah mulai, belum dikumpulkan). */
    public function currentSection(): ?AttemptSection
    {
        return $this->sections
            ->first(fn (AttemptSection $s) => $s->submitted_at === null);
    }

    /** Semua bagian sudah dikerjakan (nilai final atau masih menunggu penilaian esai). */
    public function isFinished(): bool
    {
        return in_array($this->status, [AttemptStatus::Finished, AttemptStatus::PendingReview], true);
    }

    public function isPendingReview(): bool
    {
        return $this->status === AttemptStatus::PendingReview;
    }

    public function certificate(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Certificate::class);
    }
}
