<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttemptQuestion extends Model
{
    protected $fillable = [
        'attempt_section_id', 'question_id', 'sort_order', 'correct_option_id',
        'selected_option_id', 'answer_text', 'is_flagged', 'is_correct', 'audio_play_count', 'answered_at', 'graded_by', 'graded_at', 'feedback',
    ];

    protected $hidden = ['correct_option_id', 'is_correct'];

    protected function casts(): array
    {
        return [
            'is_flagged' => 'boolean',
            'is_correct' => 'boolean',
            'answered_at' => 'datetime',
            'graded_at' => 'datetime',
        ];
    }

    public function attemptSection(): BelongsTo
    {
        return $this->belongsTo(AttemptSection::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    /** Soal esai yang belum dinilai sensei. */
    public function needsGrading(): bool
    {
        return $this->correct_option_id === null && $this->graded_at === null;
    }

    public function selectedOption(): BelongsTo
    {
        return $this->belongsTo(QuestionOption::class, 'selected_option_id');
    }
}
