<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Arsip m_exam dari aplikasi lama. */
class LegacyExam extends Model
{
    protected $fillable = [
        'legacy_id', 'kode_exam', 'jenis_tes', 'kelas', 'ulevel', 'nama_tes',
        'access_key', 'status', 'level_id', 'legacy_created_at',
    ];

    protected function casts(): array
    {
        return ['legacy_created_at' => 'datetime'];
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /** File soal dipakai bersama oleh semua paket dengan kode_exam yang sama. */
    public function files(): HasMany
    {
        return $this->hasMany(LegacyExamFile::class, 'kode_exam', 'kode_exam');
    }
}
