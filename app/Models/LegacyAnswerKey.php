<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Arsip jwb_soal dari aplikasi lama, dinormalisasi A–D → 1–4. */
class LegacyAnswerKey extends Model
{
    protected $fillable = [
        'legacy_exam_file_id', 'legacy_id', 'number', 'raw_key', 'option_label', 'is_valid', 'edited_by',
        'audio_filename', 'audio_path', 'explanation',
    ];

    protected function casts(): array
    {
        return ['is_valid' => 'boolean'];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(LegacyExamFile::class, 'legacy_exam_file_id');
    }
}
