<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    protected $fillable = [
        'level_id', 'code', 'name', 'sort_order',
        'default_duration_minutes', 'default_question_count', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function masterLabel(): string
    {
        return trim(($this->level?->masterLabel() ?? '').' · '.$this->name, ' ·');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function passages(): HasMany
    {
        return $this->hasMany(Passage::class);
    }
}
