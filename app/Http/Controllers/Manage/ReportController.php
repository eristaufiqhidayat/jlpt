<?php

namespace App\Http\Controllers\Manage;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Classroom;
use App\Models\Level;
use App\Models\ProgramStage;
use App\Models\StudentProfile;
use App\Models\TestType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Laporan & analitik untuk direktur dan admin. */
class ReportController extends Controller
{
    public function __invoke(Request $request): View
    {
        $year = $request->integer('year') ?: now()->year;
        $levelId = $request->integer('level_id') ?: null;
        $classId = $request->integer('classroom_id') ?: null;

        $students = User::where('role', Role::Student)->where('is_active', true)
            ->when($levelId, fn ($q) => $q->whereHas('profile', fn ($p) => $p->where('level_id', $levelId)))
            ->when($classId, fn ($q) => $q->whereHas('classrooms', fn ($c) => $c->where('classrooms.id', $classId)));
        $studentIds = (clone $students)->pluck('id');

        $jlpt = TestType::where('code', 'JLPT')->with('levels')->first();
        $perLevel = collect($jlpt?->levels ?? [])->mapWithKeys(fn ($l) => [
            $l->code => StudentProfile::where('level_id', $l->id)->whereIn('user_id', $studentIds)->count(),
        ]);

        // Kelulusan per tahun (3 tahun terakhir) per level JLPT: jumlah sertifikat.
        $years = range($year - 2, $year);
        $trend = collect($jlpt?->levels ?? [])->mapWithKeys(fn ($l) => [
            $l->code => collect($years)->map(fn ($y) => Certificate::whereYear('issued_at', $y)
                ->whereIn('student_id', $studentIds)
                ->whereHas('exam', fn ($e) => $e->where('level_id', $l->id))->count())->all(),
        ])->filter(fn ($vals) => array_sum($vals) > 0);

        return view('manage.reports', [
            'year' => $year, 'levelId' => $levelId, 'classId' => $classId, 'years' => $years,
            'stats' => [
                'students' => $studentIds->count(),
                'classes' => Classroom::where('is_active', true)->count(),
                'passed' => Certificate::whereYear('issued_at', $year)->whereIn('student_id', $studentIds)->distinct('student_id')->count('student_id'),
                'ready' => ProgramStage::where('stage', 'berangkat')->whereIn('status', ['proses', 'selesai'])->whereIn('student_id', $studentIds)->count(),
            ],
            'perLevel' => $perLevel,
            'trend' => $trend,
            'levels' => Level::with('testType')->get(),
            'classrooms' => Classroom::orderBy('name')->get(['id', 'name']),
            'stagesSummary' => collect(\App\Support\ProgramJepang::STAGES)->map(fn ($label, $key) => [
                'label' => $label,
                'done' => ProgramStage::where('stage', $key)->where('status', 'selesai')->whereIn('student_id', $studentIds)->count(),
                'process' => ProgramStage::where('stage', $key)->where('status', 'proses')->whereIn('student_id', $studentIds)->count(),
            ]),
        ]);
    }
}
