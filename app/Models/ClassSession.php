<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassSession extends Model
{
    protected $fillable = ['classroom_id', 'sensei_id', 'date', 'starts_at', 'ends_at', 'topic'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function sensei(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sensei_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function timeRange(): string
    {
        return substr($this->starts_at, 0, 5).' – '.substr($this->ends_at, 0, 5);
    }
}
