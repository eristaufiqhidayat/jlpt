<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Material;
use App\Support\ProgramJepang;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Materi, Kelas Saya, dan Program Jepang untuk peserta. */
class LearningController extends Controller
{
    public function materials(Request $request): View
    {
        $user = $request->user();
        $levelId = $request->integer('level_id') ?: $user->profile?->level_id;

        $materials = Material::published()->with('level')
            ->when($levelId, fn ($q) => $q->where(fn ($w) => $w->where('level_id', $levelId)->orWhereNull('level_id')))
            ->orderBy('chapter')->orderBy('sort_order')->orderBy('id')->get();
        $done = $user->completedMaterials()->pluck('materials.id')->flip();

        return view('student.materials.index', [
            'groups' => $materials->groupBy(fn ($m) => $m->chapter ?: 'Materi umum'),
            'done' => $done,
            'total' => $materials->count(),
            'levelId' => $levelId,
        ]);
    }

    public function material(Request $request, Material $material): View
    {
        abort_unless($material->status === 'published', 404);
        $user = $request->user();

        $siblings = Material::published()->where('chapter', $material->chapter)->where('level_id', $material->level_id)
            ->orderBy('sort_order')->orderBy('id')->get();
        $others = Material::published()->where('id', '!=', $material->id)
            ->where(fn ($q) => $q->where('chapter', '!=', $material->chapter)->orWhereNull('chapter'))
            ->when($material->level_id, fn ($q) => $q->where('level_id', $material->level_id))
            ->latest('id')->take(4)->get();

        return view('student.materials.show', [
            'material' => $material->load('exam', 'level'),
            'siblings' => $siblings,
            'others' => $others,
            'done' => $user->completedMaterials()->pluck('materials.id')->flip(),
        ]);
    }

    public function completeMaterial(Request $request, Material $material): RedirectResponse
    {
        $user = $request->user();
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
