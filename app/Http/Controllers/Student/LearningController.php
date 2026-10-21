<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Curriculum;
use App\Models\Material;
use App\Services\MaterialAccess;
use App\Support\ProgramJepang;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Materi, Kelas Saya, dan Program Jepang untuk peserta. */
class LearningController extends Controller
{
    /**
     * Materi per kurikulum (tab) dengan lock: kurikulum kelas peserta dan sebelumnya terbuka,
     * sesudahnya terkunci. Ditambah tab materi umum dan materi tambahan dari sensei bila ada.
     */
    public function materials(Request $request, MaterialAccess $access): View
    {
        $user = $request->user();
        $unlocked = $access->unlockedCurriculumIds($user);
        $materials = $access->visibleQuery($user)->with('files:id,material_id,type')->orderBy('chapter_no')->orderBy('chapter')->orderBy('sort_order')->orderBy('id')->get();
        $done = $user->completedMaterials()->pluck('materials.id')->flip();

        $curricula = Curriculum::where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $unlocked))
            ->orderBy('sort_order')->orderBy('name')->get();
        $lockedGroups = $curricula->reject(fn ($c) => $unlocked->contains($c->id))->groupBy('group');
        $openGroups = $curricula->filter(fn ($c) => $unlocked->contains($c->id))->pluck('group')->unique();

        // Tab: satu per kurikulum terbuka; kurikulum terkunci digabung per kelompok bila seluruh kelompoknya terkunci.
        $tabs = collect();
        foreach ($curricula as $c) {
            if ($unlocked->contains($c->id)) {
                $tabs->push(['key' => $c->code, 'label' => $c->name, 'locked' => false, 'curricula' => collect([$c]), 'order' => $c->sort_order]);
            } elseif ($openGroups->contains($c->group)) {
                $tabs->push(['key' => $c->code, 'label' => $c->name, 'locked' => true, 'curricula' => collect([$c]), 'order' => $c->sort_order]);
            } elseif (! $tabs->contains('key', 'g-'.Str::slug($c->group))) {
                $group = $lockedGroups[$c->group];
                $tabs->push(['key' => 'g-'.Str::slug($c->group), 'label' => Str::after($c->group, 'Kelas ') ?: $c->group,
                    'locked' => true, 'curricula' => $group, 'order' => $group->min('sort_order')]);
            }
        }
        if ($materials->contains(fn ($m) => ! $m->isExtra() && ! $m->curriculum_id)) {
            $tabs->push(['key' => 'umum', 'label' => 'Materi umum', 'locked' => false, 'curricula' => collect(), 'order' => PHP_INT_MAX - 1]);
        }
        if ($materials->contains(fn ($m) => $m->isExtra())) {
            $tabs->push(['key' => 'tambahan', 'label' => 'Dari sensei', 'locked' => false, 'curricula' => collect(), 'order' => PHP_INT_MAX]);
        }

        // Tab awal: kurikulum kelas peserta saat ini (urutan terbesar yang terbuka).
        $current = $curricula->filter(fn ($c) => $unlocked->contains($c->id) && ! $c->is_open)->sortBy('sort_order')->last();
        $tab = $tabs->firstWhere('key', $request->query('tab'))
            ?? ($current ? $tabs->firstWhere('key', $current->code) : null)
            ?? $tabs->firstWhere('locked', false) ?? $tabs->first();

        $items = match (true) {
            $tab === null || $tab['locked'] => collect(),
            $tab['key'] === 'umum' => $materials->filter(fn ($m) => ! $m->isExtra() && ! $m->curriculum_id),
            $tab['key'] === 'tambahan' => $materials->filter(fn ($m) => $m->isExtra()),
            default => $materials->where('curriculum_id', $tab['curricula']->first()->id)->reject(fn ($m) => $m->isExtra()),
        };

        return view('student.materials.index', [
            'tabs' => $tabs,
            'tab' => $tab,
            'groups' => $items->groupBy(fn ($m) => $m->chapterLabel()),
            'done' => $done,
            'total' => $items->count(),
            'hasClass' => $access->classroomIds($user)->isNotEmpty(),
        ]);
    }

    public function material(Request $request, Material $material, MaterialAccess $access): View
    {
        $user = $request->user();
        abort_unless($access->canView($user, $material), 403, 'Materi ini belum terbuka untukmu.');

        $visible = fn () => $access->visibleQuery($user);
        $siblings = $visible()
            ->when($material->isExtra(), fn ($q) => $q->where('kind', Material::EXTRA)->where('classroom_id', $material->classroom_id),
                fn ($q) => $q->where('kind', Material::CORE)->where('curriculum_id', $material->curriculum_id)
                    ->where('chapter_no', $material->chapter_no)->where('chapter', $material->chapter))
            ->orderBy('sort_order')->orderBy('id')->get();
        $others = $visible()->where('id', '!=', $material->id)->whereNotIn('id', $siblings->pluck('id'))
            ->when($material->curriculum_id, fn ($q) => $q->where('curriculum_id', $material->curriculum_id))
            ->latest('id')->take(4)->get();

        return view('student.materials.show', [
            'material' => $material->load('exam', 'level', 'curriculum', 'files'),
            'siblings' => $siblings,
            'others' => $others,
            'done' => $user->completedMaterials()->pluck('materials.id')->flip(),
        ]);
    }

    public function completeMaterial(Request $request, Material $material, MaterialAccess $access): RedirectResponse
    {
        $user = $request->user();
        abort_unless($access->canView($user, $material), 403, 'Materi ini belum terbuka untukmu.');

        if ($user->completedMaterials()->whereKey($material->id)->exists()) {
            $user->completedMaterials()->detach($material->id);

            return back()->with('success', 'Tanda selesai dihapus.');
        }
        $user->completedMaterials()->attach($material->id, ['completed_at' => now()]);

        return back()->with('success', 'Materi ditandai selesai. 頑張って！');
    }

    public function classes(Request $request): View
    {
        $user = $request->user();
        $classrooms = $user->classrooms()->with(['sensei:id,name', 'level'])->withCount('students')->get();
        $ids = $classrooms->pluck('id');

        $upcoming = ClassSession::with(['classroom:id,name', 'sensei:id,name'])->whereIn('classroom_id', $ids)
            ->where('date', '>=', today())->orderBy('date')->orderBy('starts_at')->take(15)->get();
        $attendance = Attendance::with('session.classroom:id,name')->where('student_id', $user->id)
            ->latest('id')->take(20)->get();

        return view('student.classes', [
            'classrooms' => $classrooms,
            'upcoming' => $upcoming,
            'attendance' => $attendance,
            'rate' => self::attendanceRate($user->id),
        ]);
    }

    public function program(Request $request): View
    {
        $user = $request->user()->load(['profile.level', 'programStages', 'programDocuments', 'classrooms']);

        return view('program.show', [
            'student' => $user,
            'stages' => $user->programStages->keyBy('stage'),
            'documents' => $user->programDocuments->keyBy('type'),
            'progress' => ProgramJepang::progress($user->programStages),
            'editable' => false,
        ]);
    }

    /** Persentase kehadiran (hadir / semua sesi yang sudah diabsen). */
    public static function attendanceRate(int $studentId): ?int
    {
        $all = Attendance::where('student_id', $studentId)->count();
        if ($all === 0) {
            return null;
        }

        return (int) round(Attendance::where('student_id', $studentId)->where('status', 'hadir')->count() / $all * 100);
    }
}
