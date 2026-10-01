<?php

namespace App\Support;

use App\Models\TestType;
use Illuminate\Support\Collection;

/** Daftar bagian (section) dikelompokkan per jenis tes + level, untuk <select> di form. */
final class SectionOptions
{
    /** @return Collection<string, Collection<int, string>> ["JLPT N4" => [section_id => "Moji Goi"], ...] */
    public static function grouped(bool $activeOnly = false): Collection
    {
        return TestType::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->with('levels.sections')
            ->orderByDesc('is_active')->orderBy('sort_order')
            ->get()
            ->flatMap(fn (TestType $type) => $type->levels->mapWithKeys(fn ($level) => [
                "{$type->name} {$level->code}" => $level->sections->pluck('name', 'id'),
            ]))
            ->filter(fn ($sections) => $sections->isNotEmpty());
    }
}
