<?php

namespace App\Support;

use App\Models\TestType;
use Illuminate\Support\Collection;

/** Daftar level dikelompokkan per jenis tes, untuk <select> "Jenis Ujian". Urutan mengikuti sort_order di Data Master. */
final class LevelOptions
{
    /** @return Collection<string, Collection<int, string>> ["JLPT" => [level_id => "JLPT N5", ...], ...] */
    public static function grouped(): Collection
    {
        return TestType::query()
            ->with('levels')
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->filter(fn (TestType $type) => $type->levels->isNotEmpty())
            ->mapWithKeys(fn (TestType $type) => [
                $type->name => $type->levels->mapWithKeys(fn ($level) => [$level->id => "{$type->name} {$level->code}"]),
            ]);
    }
}
