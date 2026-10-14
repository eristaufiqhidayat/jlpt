<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Material extends Model
{
    public const CORE = 'core';   // materi pakem: hanya Admin Pendidikan yang boleh mengubah

    public const EXTRA = 'extra'; // materi tambahan milik sensei untuk satu kelas

    protected $fillable = [
        'level_id', 'chapter', 'title', 'category', 'description', 'video_url', 'video_path',
        'pdf_path', 'ppt_path', 'duration_minutes', 'sort_order', 'exam_id', 'status', 'created_by',
        'kind', 'curriculum_id', 'chapter_no', 'classroom_id', 'available_until',
    ];

    protected $attributes = ['kind' => self::CORE];

    protected function casts(): array
    {
        return ['available_until' => 'date', 'chapter_no' => 'integer'];
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isExtra(): bool
    {
        return $this->kind === self::EXTRA;
    }

    /** Masih dalam masa tayang (tanpa batas = selalu). */
    public function isAvailable(): bool
    {
        return $this->available_until === null || ! $this->available_until->isPast() || $this->available_until->isToday();
    }

    /** Siapa yang boleh mengubah/menghapus: pakem → Admin Pendidikan; tambahan → pembuatnya (sensei) atau Admin Pendidikan. */
    public function isEditableBy(User $user): bool
    {
        if ($user->isEducationAdmin()) {
            return true;
        }

        return $this->isExtra() && $user->isSensei() && $this->created_by === $user->id;
    }

    /** Judul kelompok bab: "Bab 18 · ～ことができます", "Bab 18", atau teks bab lama. */
    public function chapterLabel(): string
    {
        if ($this->chapter_no) {
            return 'Bab '.$this->chapter_no.($this->chapter ? ' · '.$this->chapter : '');
        }

        return $this->chapter ?: 'Materi umum';
    }

    /** Belum lewat masa tayang. */
    public function scopeAvailable(Builder $q): void
    {
        $q->where(fn ($w) => $w->whereNull('available_until')->orWhereDate('available_until', '>=', today()));
    }

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
