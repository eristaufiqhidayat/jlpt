<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Klasifikasi kelas / kurikulum (Basic → Minna I/II, JFT, N3, SSW per bidang). Dikelola Admin Pendidikan. */
class Curriculum extends Model
{
    protected $table = 'curricula';

    protected $fillable = ['group', 'name', 'code', 'scope', 'chapter_from', 'chapter_to', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'chapter_from' => 'integer', 'chapter_to' => 'integer', 'sort_order' => 'integer'];
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** "Bab 1–25" atau null bila kurikulum tidak berbasis bab. */
    public function chapterRange(): ?string
    {
        if ($this->chapter_from === null) {
            return null;
        }

        return $this->chapter_to ? "Bab {$this->chapter_from}–{$this->chapter_to}" : "Bab {$this->chapter_from}";
    }

    public function masterLabel(): string
    {
        return $this->name;
    }
}
