<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** File video/PDF/PPT/audio milik satu materi. Disimpan privat di storage/app/private/materials/{material_id}/. */
class MaterialFile extends Model
{
    /** jenis => [label, ikon, aturan mimes, ukuran maks KB, accept di input file] */
    public const TYPES = [
        'video' => ['Video', '🎬', 'mp4,webm,mov', 512000, 'video/mp4,video/webm,video/quicktime'],
        'pdf' => ['PDF', '📄', 'pdf', 51200, 'application/pdf'],
        'ppt' => ['PPT', '📊', 'ppt,pptx,pdf', 102400, '.ppt,.pptx,.pdf'],
        'audio' => ['Audio', '🎧', 'mp3,m4a,wav,ogg', 102400, 'audio/mpeg,audio/mp4,audio/wav,audio/ogg'],
    ];

    /** Batas jumlah file per jenis dalam satu materi. */
    public const MAX_PER_TYPE = 10;

    protected $fillable = ['material_id', 'type', 'path', 'original_name', 'size', 'sort_order'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'sort_order' => 'integer'];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function label(): string
    {
        return $this->original_name ?: basename($this->path);
    }

    public function sizeLabel(): ?string
    {
        if (! $this->size) {
            return null;
        }

        return $this->size >= 1048576 ? number_format($this->size / 1048576, 1, ',', '.').' MB' : max(1, (int) round($this->size / 1024)).' KB';
    }
}
