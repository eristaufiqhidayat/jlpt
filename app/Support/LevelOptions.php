<?php

namespace App\Support;

use App\Models\Level;
use App\Models\Section;
use App\Models\TestType;
use Illuminate\Support\Collection;

/**
 * Daftar level aktif dikelompokkan per jenis tes aktif, untuk <select> "Jenis Ujian".
 * Urutan mengikuti sort_order di Data Master.
 */
final class LevelOptions
{
    /**
     * @param  int|null  $keepLevelId  level yang tetap ditampilkan walau nonaktif (mis. soal/ujian lama yang sedang diedit)
     * @return Collection<string, Collection<int, string>> ["JLPT" => [level_id => "N5", ...], ...]
     */
    public static function grouped(?int $keepLevelId = null): Collection
    {
        $keepTypeId = $keepLevelId ? Level::whereKey($keepLevelId)->value('test_type_id') : null;

        return TestType::query()
            ->where(fn ($q) => $q->where('is_active', true)->when($keepTypeId, fn ($w) => $w->orWhere('id', $keepTypeId)))
            ->with(['levels' => fn ($q) => $q->where(fn ($l) => $l->where('is_active', true)
                ->when($keepLevelId, fn ($w) => $w->orWhere('id', $keepLevelId)))])
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->filter(fn (TestType $type) => $type->levels->isNotEmpty())
            ->mapWithKeys(fn (TestType $type) => [
                $type->name => $type->levels->mapWithKeys(fn ($level) => [$level->id => $level->code]),
            ]);
    }

    /**
     * Bagian aktif per level, untuk dropdown "Bagian" yang mengikuti "Jenis Ujian".
     *
     * @param  Collection<int, int>  $levelIds
     * @param  int|null  $keepSectionId  bagian yang tetap ditampilkan walau nonaktif
     * @return Collection<int, Collection<int, string>> [level_id => [section_id => "Moji Goi", ...], ...]
     */
    public static function sectionsByLevel(Collection $levelIds, ?int $keepSectionId = null): Collection
    {
        return Section::query()
            ->whereIn('level_id', $levelIds)
            ->where(fn ($q) => $q->where('is_active', true)->when($keepSectionId, fn ($w) => $w->orWhere('id', $keepSectionId)))
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'level_id', 'name'])
            ->groupBy('level_id')
            ->map(fn ($sections) => $sections->pluck('name', 'id'));
    }
}
