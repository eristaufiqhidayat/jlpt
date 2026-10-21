<?php

namespace App\Services;

use App\Models\Classroom;
use App\Models\Curriculum;
use App\Models\Material;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Aturan siapa boleh membuka materi (notulen 3.b "lock").
 *
 * Peserta membuka kurikulum kelasnya sendiri dan semua kurikulum dengan urutan lebih kecil
 * (mis. peserta Minna II tetap bisa mengulang Minna I), tetapi tidak kurikulum sesudahnya.
 * Materi bisa dibuka kapan saja dan berulang selama terbit dan belum lewat masa tayang.
 *
 * - Materi pakem berkurikulum : terbuka bila kurikulumnya terbuka.
 * - Kurikulum terbuka (is_open, mis. Materi Umum): tanpa lock, untuk semua peserta, disaring per level materi.
 * - Materi lama tanpa kurikulum: disaring per level peserta seperti sebelumnya.
 * - Materi tambahan sensei    : hanya untuk anggota kelas tujuan.
 */
class MaterialAccess
{
    /** @var array<int, array{curricula: Collection, open: Collection, classes: Collection}> */
    private array $cache = [];

    /** Kelas yang diikuti peserta (aktif maupun sudah selesai). */
    public function classroomIds(User $student): Collection
    {
        return $this->resolve($student)['classes'];
    }

    /** ID kurikulum yang terbuka untuk peserta. */
    public function unlockedCurriculumIds(User $student): Collection
    {
        return $this->resolve($student)['curricula'];
    }

    public function isUnlocked(User $student, Curriculum $curriculum): bool
    {
        return $this->unlockedCurriculumIds($student)->contains($curriculum->id);
    }

    /** Semua materi yang boleh dibuka peserta: terbit, dalam masa tayang, dan lolos lock. */
    public function visibleQuery(User $student): Builder
    {
        $resolved = $this->resolve($student);
        $locked = $resolved['curricula']->diff($resolved['open'])->values();
        $open = $resolved['open'];
        $classes = $resolved['classes'];
        $levelId = $student->profile?->level_id;
        $byLevel = fn ($l) => $l->when($levelId, fn ($w) => $w->where(fn ($x) => $x->whereNull('level_id')->orWhere('level_id', $levelId)));

        return Material::query()->published()->available()->where(function (Builder $q) use ($locked, $open, $classes, $byLevel) {
            $q->where(fn ($c) => $c->where('kind', Material::CORE)->whereIn('curriculum_id', $locked))
                ->orWhere(fn ($o) => $byLevel($o->where('kind', Material::CORE)->whereIn('curriculum_id', $open)))
                ->orWhere(fn ($g) => $byLevel($g->where('kind', Material::CORE)->whereNull('curriculum_id')))
                ->orWhere(fn ($e) => $e->where('kind', Material::EXTRA)->whereIn('classroom_id', $classes));
        });
    }

    public function canView(User $student, Material $material): bool
    {
        return $this->visibleQuery($student)->whereKey($material->id)->exists();
    }

    /** Kelas yang dipegang sensei: sebagai wali kelas atau pengajar salah satu sesi. */
    public static function taughtClassroomIds(User $sensei): Collection
    {
        return Classroom::where('sensei_id', $sensei->id)
            ->orWhereHas('sessions', fn ($q) => $q->where('sensei_id', $sensei->id))
            ->pluck('id');
    }

    /** ID kurikulum terbuka (tanpa lock) yang aktif. */
    public function openCurriculumIds(User $student): Collection
    {
        return $this->resolve($student)['open'];
    }

    /** @return array{curricula: Collection, open: Collection, classes: Collection} */
    private function resolve(User $student): array
    {
        return $this->cache[$student->id] ??= (function () use ($student) {
            $classes = $student->classrooms()->get(['classrooms.id', 'classrooms.curriculum_id']);
            $own = Curriculum::whereIn('id', $classes->pluck('curriculum_id')->filter())->get(['id', 'sort_order']);

            $open = Curriculum::open()->active()->pluck('id');
            $curricula = $own->isEmpty() ? collect() : Curriculum::where('sort_order', '<', $own->max('sort_order'))
                ->pluck('id')->merge($own->pluck('id'));

            return ['curricula' => $curricula->merge($open)->unique()->values(), 'open' => $open, 'classes' => $classes->pluck('id')];
        })();
    }
}
