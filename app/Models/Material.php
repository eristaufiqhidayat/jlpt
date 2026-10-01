<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Material extends Model
{
    protected $fillable = [
        'level_id', 'chapter', 'title', 'category', 'description', 'video_url', 'video_path',
        'pdf_path', 'ppt_path', 'duration_minutes', 'sort_order', 'exam_id', 'status', 'created_by',
    ];

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function completedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'material_progress', 'material_id', 'student_id')->withPivot('completed_at');
    }

    public function scopePublished(Builder $q): void
    {
        $q->where('status', 'published');
    }

    /** URL embed YouTube bila video_url adalah tautan YouTube. */
    public function youtubeEmbed(): ?string
    {
        if (! $this->video_url) {
            return null;
        }
        if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,})~', $this->video_url, $m)) {
            return 'https://www.youtube-nocookie.com/embed/'.$m[1];
        }

        return null;
    }

    public function icon(): string
    {
        return match (true) {
            str_contains(strtolower((string) $this->category), 'kanji') => '字',
            str_contains(strtolower((string) $this->category), 'choukai'), str_contains(strtolower((string) $this->category), 'listening') => '🎧',
            (bool) ($this->video_url || $this->video_path) => '🎬',
            default => '📘',
        };
    }
}
