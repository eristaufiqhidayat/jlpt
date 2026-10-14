<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Classroom extends Model
{
    protected $fillable = ['name', 'level_id', 'curriculum_id', 'sensei_id', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function sensei(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sensei_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'classroom_student', 'classroom_id', 'student_id')->withTimestamps()->orderBy('name');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class)->orderBy('date')->orderBy('starts_at');
    }
}
