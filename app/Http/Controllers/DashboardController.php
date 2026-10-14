<?php

namespace App\Http\Controllers;

use App\Enums\AttemptStatus;
use App\Enums\ExamStatus;
use App\Enums\LegacyKeyStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\AttemptQuestion;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\LegacyExamFile;
use App\Models\Payment;
use App\Models\Question;
use App\Models\User;
use App\Http\Controllers\Student\LearningController;
use App\Models\ClassSession;
use App\Models\Material;
use App\Support\ProgramJepang;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        return match ($user->role) {
            Role::Admin => $this->admin(),
            Role::Sensei => $this->sensei($user),
            Role::Student => $this->student($user),
            Role::Director => redirect()->route('reports'),
            Role::EducationAdmin => redirect()->route('manage.master.index'),
        };
    }

    private function admin(): View
    {
        return view('dashboard.admin', [
            'userCounts' => User::selectRaw('role, COUNT(*) as total')->groupBy('role')->pluck('total', 'role'),
            'openExams' => Exam::where('status', ExamStatus::Open)->count(),
            'attemptsToday' => ExamAttempt::whereDate('started_at', today())->count(),
            'pendingPayments' => Payment::where('status', PaymentStatus::Pending)->count(),
            'questionCount' => Question::published()->count(),
        ]);
    }

    private function sensei(User $user): View
    {
        return view('dashboard.sensei', [
            'todaySessions' => ClassSession::with('classroom:id,name')->whereDate('date', today())
                ->where(fn ($q) => $q->where('sensei_id', $user->id)->orWhereHas('classroom', fn ($c) => $c->where('sensei_id', $user->id)))
                ->orderBy('starts_at')->get(),
            'myExams' => $user->exams()->with('level.testType')->withCount('attempts')->latest()->take(5)->get(),
            'questionCount' => Question::published()->count(),
            'draftCount' => Question::where('status', 'draft')->count(),
            'pendingEssays' => AttemptQuestion::whereNull('correct_option_id')->whereNull('graded_at')
                ->whereHas('attemptSection', fn ($q) => $q->whereNotNull('submitted_at'))->count(),
            'legacyIssues' => LegacyExamFile::whereIn('key_status', [LegacyKeyStatus::InvalidKeys, LegacyKeyStatus::Incomplete])->count(),
        ]);
    }

    private function student(User $user): View
    {
        $user->load(['profile.level', 'programStages']);
        $levelId = $user->profile?->level_id;
        $materialsQuery = Material::published()->when($levelId, fn ($q) => $q->where(fn ($w) => $w->where('level_id', $levelId)->orWhereNull('level_id')));
        $totalMaterials = (clone $materialsQuery)->count();
        $doneMaterials = $user->completedMaterials()->whereIn('materials.id', (clone $materialsQuery)->pluck('id'))->count();
        $classIds = $user->classrooms()->pluck('classrooms.id');

        return view('dashboard.student', [
            'level' => $user->profile?->level,
            'attendanceRate' => LearningController::attendanceRate($user->id),
            'materialsDone' => $doneMaterials,
            'materialsTotal' => $totalMaterials,
            'learningProgress' => $totalMaterials ? (int) round($doneMaterials / $totalMaterials * 100) : null,
            'certificateCount' => $user->certificates()->count(),
            'stages' => $user->programStages->keyBy('stage'),
            'hasProgram' => $user->programStages->isNotEmpty(),
            'latestMaterials' => (clone $materialsQuery)->latest('id')->take(3)->get(),
            'todaySessions' => ClassSession::with(['sensei:id,name', 'classroom:id,name'])->whereIn('classroom_id', $classIds)
                ->whereDate('date', today())->orderBy('starts_at')->get(),
            'ongoing' => $user->attempts()->with('exam')->where('status', AttemptStatus::Ongoing)->latest()->get(),
            'pendingPayments' => $user->payments()->with('exam')->where('status', PaymentStatus::Pending)
                ->where('expires_at', '>', now())->latest()->get(),
            'history' => $user->attempts()->with('exam.level')->where('status', '!=', AttemptStatus::Ongoing)->latest()->take(5)->get(),
            'stats' => [
                'finished' => $user->attempts()->where('status', '!=', AttemptStatus::Ongoing)->count(),
                'average' => $user->attempts()->where('status', AttemptStatus::Finished)->avg('score'),
                'best' => $user->attempts()->where('status', AttemptStatus::Finished)->max('score'),
                'pending' => $user->attempts()->where('status', AttemptStatus::PendingReview)->count(),
            ],
        ]);
    }
}
