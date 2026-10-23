<?php

namespace App\Support;

use App\Models\TestType;
use Illuminate\Support\Collection;

/**
 * Pilihan "Klasifikasi Ujian" sesuai Data Master: jenis tes → level (mis. JLPT → N5, N4, N3).
 * Hanya jenis tes dan level yang aktif; jenis tes tanpa level aktif tidak ditampilkan.
 */
class ExamClassification
{
    /** @return Collection<int, TestType> jenis tes dengan relasi `levels` (aktif, terurut) */
    public static function options(): Collection
    {
        return TestType::query()
            ->where('is_active', true)
            ->whereHas('levels', fn ($q) => $q->where('is_active', true))
            ->with(['levels' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('code')])
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    /** Label level, mis. "JLPT N5". */
    public static function label(TestType $type, $level): string
    {
        return trim($type->name.' '.$level->code);
    }
}
