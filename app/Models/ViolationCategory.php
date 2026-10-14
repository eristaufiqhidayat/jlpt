<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Kategori catatan batsu (pelanggaran). Dikelola Admin Pendidikan. */
class ViolationCategory extends Model
{
    /** Warna badge yang tersedia di CSS aplikasi. */
    public const COLORS = ['warn' => 'Oranye', 'err' => 'Merah', 'info' => 'Biru', 'ok' => 'Hijau', 'grey' => 'Abu-abu'];

    protected $fillable = ['name', 'description', 'color', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function masterLabel(): string
    {
        return 'Kategori batsu '.$this->name;
    }
}
