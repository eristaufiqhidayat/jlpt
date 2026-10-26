<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jejak unduhan backup tabel: siapa, kapan, format apa, tabel mana. */
class BackupLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'format', 'tables', 'ip'];

    protected function casts(): array
    {
        return ['tables' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
