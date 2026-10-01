<?php

namespace App\Http\Controllers\Sensei;

use App\Exceptions\ExamAccessException;
use App\Http\Controllers\Controller;
use App\Models\AttemptQuestion;
use App\Models\Exam;
use App\Services\ExamAttemptService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Penilaian jawaban esai. Sensei melihat esai dari ujiannya sendiri; admin melihat semua.
 */
class GradingController extends Controller
{
    public function index(Request $request): View
    {
        $items = $this->scope($request)
            ->whereNull('graded_at')
            ->when($request->filled('exam_id'), fn ($q) => $q->whereHas('attemptSection.attempt', fn ($a) => $a->where('exam_id', $request->integer('exam_id'))))
            ->with(['question', 'attemptSection.attempt.student:id,name', 'attemptSection.attempt.exam:id,title,code'])
            ->oldest('answered_at')
            ->paginate(20)->withQueryString();

        $exams = Exam::query()
            ->when(! $request->user()->isAdmin(), fn ($q) => $q->where('sensei_id', $request->user()->id))
            ->orderByDesc('id')->get(['id', 'title', 'code']);

        return view('sensei.grading.index', compact('items', 'exams'));
    }

    public function store(Request $request, AttemptQuestion $item, ExamAttemptService $service): RedirectResponse
    {
        abort_unless($this->scope($request)->whereKey($item->id)->exists(), 403);

        $data = $request->validate([
            'is_correct' => ['required', 'boolean'],
            'feedback' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $service->gradeEssay($item, (bool) $data['is_correct'], $request->user(), $data['feedback'] ?? null);
        } catch (ExamAccessException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Nilai tersimpan.');
    }

    /** Esai dari bagian yang sudah dikumpulkan, dibatasi ke ujian milik sensei. */
    private function scope(Request $request): Builder
    {
        return AttemptQuestion::query()
            ->whereNull('correct_option_id')
            ->whereHas('attemptSection', fn ($q) => $q->whereNotNull('submitted_at'))
            ->when(! $request->user()->isAdmin(), fn ($q) => $q->whereHas(
                'attemptSection.attempt.exam', fn ($e) => $e->where('sensei_id', $request->user()->id)
            ));
    }
}
