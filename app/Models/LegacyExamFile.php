<?php

namespace App\Models;

use App\Enums\LegacyKeyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Arsip detail_soal (satu file PDF per bagian) dari aplikasi lama. */
class LegacyExamFile extends Model
{
    protected $fillable = [
        'legacy_id', 'kode_exam', 'section_name', 'section_id', 'stored_filename',
        'original_filename', 'file_path', 'file_mime', 'question_count', 'duration_minutes',
        'key_count', 'valid_key_count', 'key_status', 'issues', 'legacy_created_at',
    ];

    protected function casts(): array
    {
        return [
            'key_status' => LegacyKeyStatus::class,
            'issues' => 'array',
            'legacy_created_at' => 'datetime',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function exams(): HasMany
    {
        return $this->hasMany(LegacyExam::class, 'kode_exam', 'kode_exam');
    }

    public function answerKeys(): HasMany
    {
        return $this->hasMany(LegacyAnswerKey::class)->orderBy('number');
    }

    /** Soal bank yang sudah diinput ulang dari file ini. */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'legacy_file_id');
    }
}
