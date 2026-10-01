<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttemptSection extends Model
{
    protected $fillable = [
        'exam_attempt_id', 'exam_section_id', 'sort_order', 'duration_minutes',
        'started_at', 'deadline_at', 'submitted_at', 'submitted_by',
        'correct_count', 'question_count', 'score',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'deadline_at' => 'datetime',
            'submitted_at' => 'datetime',
            'score' => 'decimal:2',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }

    public function examSection(): BelongsTo
    {
        return $this->belongsTo(ExamSection::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(AttemptQuestion::class)->orderBy('sort_order');
    }

    public function isRunning(): bool
    {
        return $this->started_at !== null && $this->submitted_at === null;
    }

    public function isOverdue(): bool
    {
        return $this->isRunning() && $this->deadline_at->isPast();
    }

    /** Sisa waktu dalam detik, dihitung dari jam server. */
    public function remainingSeconds(): int
    {
        if (! $this->isRunning()) {
            return 0;
        }

        return max(0, (int) now()->diffInSeconds($this->deadline_at, false));
    }
}
