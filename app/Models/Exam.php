<?php

namespace App\Models;

use App\Enums\ExamStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Exam extends Model
{
    protected $fillable = [
        'sensei_id', 'level_id', 'code', 'title', 'description', 'opens_at', 'closes_at',
        'is_paid', 'price', 'pass_score', 'audio_play_limit', 'shuffle_options', 'show_result_immediately', 'status',
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'is_paid' => 'boolean',
            'price' => 'integer',
            'shuffle_options' => 'boolean',
            'show_result_immediately' => 'boolean',
            'status' => ExamStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Exam $exam) {
            $exam->code ??= static::generateCode();
        });
    }

    /** Kode akses 6 karakter tanpa huruf/angka yang mirip (0/O, 1/I). */
    public static function generateCode(): string
    {
        do {
            $code = collect(str_split(str_repeat('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', 2)))
                ->shuffle()->take(6)->implode('');
        } while (static::where('code', $code)->exists());

        return $code;
    }

    public function sensei(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sensei_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ExamSection::class)->orderBy('sort_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isOpen(): bool
    {
        return $this->status === ExamStatus::Open
            && ($this->opens_at === null || $this->opens_at->isPast())
            && ($this->closes_at === null || $this->closes_at->isFuture());
    }

    /** null bila ujian tidak memakai nilai minimal lulus. */
    public function passed(?float $score): ?bool
    {
        return $this->pass_score === null || $score === null ? null : $score >= $this->pass_score;
    }

    public function totalDurationMinutes(): int
    {
        return (int) $this->sections->sum('duration_minutes');
    }
}
