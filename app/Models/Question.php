<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Question extends Model
{
    protected $fillable = [
        'section_id', 'passage_id', 'created_by', 'type', 'mondai', 'number_in_passage',
        'stem', 'image_path', 'audio_path', 'star_position', 'correct_order',
        'explanation', 'answer_reference', 'status', 'legacy_file_id', 'legacy_number',
    ];

    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'status' => ContentStatus::class,
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function passage(): BelongsTo
    {
        return $this->belongsTo(Passage::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('label');
    }

    public function correctOption(): HasOne
    {
        return $this->hasOne(QuestionOption::class)->where('is_correct', true);
    }

    public function legacyFile(): BelongsTo
    {
        return $this->belongsTo(LegacyExamFile::class, 'legacy_file_id');
    }

    /** Soal yang siap dipakai ujian. */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', ContentStatus::Published);
    }
}
