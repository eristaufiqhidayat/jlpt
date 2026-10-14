<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jejak perubahan data master akademik: siapa, kapan, nilai lama → baru. */
class MasterDataLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = [
        'created' => 'Ditambahkan',
        'updated' => 'Diubah',
        'activated' => 'Diaktifkan',
        'deactivated' => 'Dinonaktifkan',
    ];

    protected $fillable = ['user_id', 'subject_type', 'subject_id', 'subject_label', 'action', 'changes'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }
}
