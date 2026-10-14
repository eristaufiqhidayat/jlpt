<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestType extends Model
{
    protected $fillable = [
        'code', 'name', 'is_active', 'sort_order',
        'max_score', 'pass_score', 'score_approved_by', 'score_approved_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'max_score' => 'integer',
            'pass_score' => 'integer',
            'score_approved_at' => 'date',
        ];
    }

    public function masterLabel(): string
    {
        return $this->name;
    }

    public function levels(): HasMany
    {
        return $this->hasMany(Level::class)->orderBy('sort_order');
    }
}
